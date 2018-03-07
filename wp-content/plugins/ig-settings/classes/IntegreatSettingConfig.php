<?php

/*
 * This class represents a setting configuration, which means the value of a setting.
 * It also provides some static methods to support the processing of the setting configurations.
 */

class IntegreatSettingConfig {

	const PREFIXES = [
		'EAE',
		'Landkreis',
		'Kreis',
		'Stadt'
	];
	public $id;
	public $setting_id;
	public $value;

	public function __construct($setting_config = []) {
		$setting_config = (object) $setting_config;
		$this->id = isset($setting_config->id) ? (int) $setting_config->id : null;
		$this->setting_id = isset($setting_config->setting_id) ? (int) $setting_config->setting_id : null;
		$this->value = isset($setting_config->value) && $setting_config->value !== '' ? htmlspecialchars($setting_config->value) : null;
	}

	public function validate() {
		global $wpdb;
		if ($this->id) {
			if ($wpdb->query($wpdb->prepare('SELECT id from ' . self::get_table_name() . ' WHERE id = %d', $this->id)) !== 1) {
				$_SESSION['ig-admin-notices'][] = [
					'type' => 'error',
					'message' => 'There is no setting config with the id "' . $this->id . '"'
				];
				$_SESSION['ig-current-error'][] = 'id';
				return false;
			}
		}
		if (!$this->setting_id) {
			$_SESSION['ig-admin-notices'][] = [
				'type' => 'error',
				'message' => 'You have to specify a setting id for this setting config'
			];
			$_SESSION['ig-current-error'][] = 'setting_id';
			return false;
		}
		$setting = IntegreatSetting::get_setting_by_id($this->setting_id);
		if ($setting === false) {
			$_SESSION['ig-admin-notices'][] = [
				'type' => 'error',
				'message' => 'There is no setting with the id "' . $this->setting_id . '"'
			];
			$_SESSION['ig-current-error'][] = 'setting_id';
			return false;
		}
		if ($setting->type === 'bool' && !in_array($this->value, ['0', '1'])) {
			$_SESSION['ig-admin-notices'][] = [
				'type' => 'error',
				'message' => 'The value "' . $this->value . '" is not boolean'
			];
			$_SESSION['ig-current-error'][] = $setting->alias;
			return false;
		}
		if ($setting->alias === 'plz') {
			if ($this->value === '') {
				/*
				 * If the plz is empty we have to check whether it was set before
				 * and if so we have to disable all extras which depend on the plz
				*/
				$old_setting_config = self::get_setting_config_by_id($setting->id);
				if ($old_setting_config && $old_setting_config->value) {
					foreach (IntegreatExtra::get_extras() as $extra) {
						if (strpos($extra->url, '{plz}') !== false || strpos($extra->post, '{plz}') !== false) {
							$extra_config = IntegreatExtraConfig::get_extra_config_by_id($extra->id);
							if ($extra_config && $extra_config->enabled) {
								$extra_config->enabled = false;
								if ($extra_config->validate() && $extra_config->save()) {
									$_SESSION['ig-admin-notices'][] = [
										'type' => 'info',
										'message' => 'The extra "' . $extra->name . '" was disabled because it depends on the setting "plz" for this location'
									];
								}
							}
						}
					}
				}
			} elseif (!ctype_digit($this->value) || strlen($this->value) !== 5) {
				$_SESSION['ig-admin-notices'][] = [
					'type' => 'error',
					'message' => 'The PLZ "' . $this->value . '" is not valid'
				];
				$_SESSION['ig-current-error'][] = $setting->alias;
				return false;
			}
		}
		/*
		 * for the 'disabled'-setting, we actually don't use the value from the ig_setting_config-table,
		 * but the value in the global options table instead
		 */
		if ($setting->alias === 'hidden') {
			global $current_blog;
			if ($this->value === '1') {
				update_blog_public(0, 0);
				$current_blog->public = 0;
			} else {
				update_blog_public(0, 1);
				$current_blog->public = 1;
			}
		}
		return true;
	}

	public function save() {
		global $wpdb;
		if ($this->id) {
			$setting_array = (array) $this;
			unset($setting_array['id']);
			return $wpdb->update(self::get_table_name(), $setting_array, ['id' => $this->id]);
		} else {
			return $wpdb->insert(self::get_table_name(), (array) $this);
		}
	}

	public function delete() {
		global $wpdb;
		return $wpdb->delete(self::get_table_name(), ['id' => $this->id]);
	}

	private static function get_table_name() {
		return $GLOBALS['wpdb']->prefix . 'ig_settings_config';
	}

	public static function get_setting_config_by_id($id) {
		global $wpdb;
		$setting_config = $wpdb->get_row($wpdb->prepare('SELECT * from ' . self::get_table_name() . ' WHERE setting_id = %d', $id));
		if ($setting_config) {
			return new IntegreatSettingConfig($setting_config);
		} else {
			return false;
		}
	}

	public static function get_setting_configs() {
		global $wpdb;
		return array_map(function ($setting_config) {
			return new IntegreatSettingConfig($setting_config);
		},
			$wpdb->get_results('SELECT * from ' . self::get_table_name())
		);
	}

	public static function get_default_setting_configs() {
		global $wpdb;
		$site = get_blog_details(get_current_blog_id());
		$location_prefix = null;
		$location_name = $site->blogname;
		foreach (self::PREFIXES as $prefix) {
			if (strpos($site->blogname, $prefix) === 0) {
				$location_prefix = $prefix;
				$location_name = substr($site->blogname, strlen($prefix) + 1);
				break;
			}
		}
		return [
			new IntegreatSettingConfig([
				'setting_id' => IntegreatSetting::get_setting_by_alias('prefix')->id,
				'value' => $location_prefix
			]),
			new IntegreatSettingConfig([
				'setting_id' => IntegreatSetting::get_setting_by_alias('name_without_prefix')->id,
				'value' => $location_name
			]),
			new IntegreatSettingConfig([
				'setting_id' => IntegreatSetting::get_setting_by_alias('plz')->id,
				'value' => $wpdb->get_var("SELECT option_value FROM $wpdb->options WHERE option_name = 'ige-zip'")
			]),
			new IntegreatSettingConfig([
				'setting_id' => IntegreatSetting::get_setting_by_alias('events')->id,
				'value' => $wpdb->get_var("SELECT option_value FROM $wpdb->options WHERE option_name = 'ige-evts'")
			]),
			new IntegreatSettingConfig([
				'setting_id' => IntegreatSetting::get_setting_by_alias('push-notifications')->id,
				'value' => $wpdb->get_var("SELECT option_value FROM $wpdb->options WHERE option_name = 'ige-pn'")
			]),
			new IntegreatSettingConfig([
				'setting_id' => IntegreatSetting::get_setting_by_alias('hidden')->id,
				'value' => (!$site->public || $site->spam || $site->deleted || $site->archived || $site->mature)
			]),
		];
	}

	public static function create_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		$foreign_table_name = IntegreatSetting::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $table_name (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					setting_id mediumint(9) NOT NULL,
					value text DEFAULT NULL,
					PRIMARY KEY  (id),
					FOREIGN KEY (setting_id)
						REFERENCES $foreign_table_name(id)
						ON DELETE CASCADE
				) $charset_collate;";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		$result = dbDelta($sql);
		if (isset($result[self::get_table_name()]) && $result[self::get_table_name()] == 'Created table ' . self::get_table_name()) {
			foreach(self::get_default_setting_configs() as $setting_config) {
				if ($setting_config->validate()) {
					$setting_config->save();
				}
			}
		}
	}

	public static function delete_table() {
		global $wpdb;
		$wpdb->query('DROP TABLE IF EXISTS $table_name' . self::get_table_name());
	}

	public static function form() {
		$error = isset($_SESSION['ig-current-error']) ? $_SESSION['ig-current-error'] : [];
		$form = '
			<form action="' . $_SERVER['REQUEST_URI'] . '" method="post">
		';
		$settings = IntegreatSetting::get_settings();
		usort($settings, function ($a, $b) {
			return strcmp($b->type, $a->type);
		});
		foreach ($settings as $setting) {
			if (!$setting_config = self::get_setting_config_by_id($setting->id)) {
				$setting_config = new IntegreatSettingConfig([
					'setting_id' => $setting->id
				]);
			}
			if ($setting->type === 'string') {
				$form .= '
					<div>
						<label for="' . $setting->id . '">' . $setting->name . '</label>
						<input type="text" class="' . ( $error ? ( in_array($setting->alias, $error) ? 'ig-error' : 'ig-success') : '') . '" name="' . $setting->id . '" value="' . (in_array($setting->alias, $error) ? htmlspecialchars($_POST[$setting->id]) : $setting_config->value) . '">
					</div>
					<br>
				';
			} elseif ($setting->type === 'bool') {
				global $current_blog;
				$hidden = (!$current_blog->public OR $current_blog->spam OR $current_blog->deleted OR $current_blog->archived OR $current_blog->mature);
				$form .= '
					<div>
						<label for="' . $setting->id . '">' . $setting->name . '</label>
						<label class="switch">
							<input type="checkbox" name="' . $setting->id . '"' . (($setting->alias === 'hidden' && $hidden) || ($setting->alias !== 'hidden' && $setting_config->value === '1') ? ' checked' : '') . '>
							<span class="slider round"></span>
						</label>
					</div>
					<br><br><br>
				';
			}
		}
		$form .= '
				<input class="button button-primary" type="submit" name="submit" value=" Save ">
			</form>
		';
		return $form;
	}

	public static function handle_request() {
		if (isset($_POST['submit'])) {
			$changes_made = false;
			$error_occurred = false;
			foreach (IntegreatSetting::get_settings() as $setting) {
				if (!$setting_config = self::get_setting_config_by_id($setting->id)) {
					$setting_config = new IntegreatSettingConfig([
						'setting_id' => $setting->id
					]);
				}
				if ($setting->type === 'bool') {
					if (isset($_POST[$setting_config->setting_id])) {
						$setting_config->value = '1';
					} else {
						$setting_config->value = '0';
					}
				} elseif ($setting->type === 'string') {
					if (isset($_POST[$setting_config->setting_id])) {
						$setting_config->value = $_POST[$setting_config->setting_id];
					} else {
						$_SESSION['ig-admin-notices'][] = [
							'type' => 'error',
							'message' => 'Form was not submitted properly (the POST-parameter "' . $setting_config->setting_id . '" is missing)'
						];
						$error_occurred = true;
						continue;
					}
				}
				if (!$setting_config->validate()) {
					$error_occurred = true;
					continue;
				}
				$saved = $setting_config->save();
				if ($saved === false) {
					$_SESSION['ig-admin-notices'][] = [
						'type' => 'error',
						'message' => 'Setting "' . $setting->name . '" could not be saved'
					];
					$error_occurred = true;
					continue;
				}
				if ($saved !== 0) {
					$changes_made = true;
					continue;
				}
			}
			if ($error_occurred) {
				return false;
			}
			if (!$changes_made) {
				$_SESSION['ig-admin-notices'][] = [
					'type' => 'info',
					'message' => 'Settings have not been changed'
				];
				return false;
			}
			$_SESSION['ig-admin-notices'][] = [
				'type' => 'success',
				'message' => 'Settings saved successfully'
			];
		}
		return true;
	}

}