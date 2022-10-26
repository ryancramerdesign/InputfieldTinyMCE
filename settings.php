<?php namespace ProcessWire;

/**
 * InputfieldTinyMCETools
 *
 * Helper for managing TinyMCE settings and defaults
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class InputfieldTinyMCESettings extends Wire {

	/**
	 * @var InputfieldTinyMCE 
	 * 
	 */
	protected $inputfield;

	/**
	 * HTML5 inline elements
	 * 
	 * @var string 
	 * 
	 */
	static protected $inlines =
		'/a/abbr/acronym/audio/b/bdi/bdo/big/br/button/canvas/cite/code/data/datalist/' .
		'/del/dfn/em/embed/i/iframe/img/input/ins/kbd/label/map/mark/meter/noscript/' .
		'/object/output/picture/progress/q/ruby/s/samp/script/select/slot/small/span/strong/' .
		'/sub/sup/svg/template/textarea/time/u/tt/var/video/wbr/';

	/**
	 * HTML5 block elements
	 * 
	 * @var string 
	 * 
	 */
	static protected $blocks =
		'/address/article/aside/blockquote/dd/details/dialog/div/dl/dt/fieldset/figcaption/' .
		'/figure/footer/form/h1/h2/h3/h4/h5/h6/header/hgroup/hr/li/main/nav/ol/p/pre/' .
		'/section/table/ul/';

	/**
	 * Runtime caches shared among all instances
	 * 
	 * @var array 
	 * 
	 */
	static protected $caches = array(
		'defaults' => array(),
		'settings' => array(), 
		'alignClasses' => array(),
		'renderReadyInline' => array(), 
		'langSettings' => array(), 
	);

	/**
	 * Construct
	 * 
	 * @param InputfieldTinyMCE $inputfield
	 * 
	 */
	public function __construct(InputfieldTinyMCE $inputfield) {
		parent::__construct();
		$inputfield->wire($this);
		$this->inputfield = $inputfield;
	}
	
	/**
	 * Get settings from Inputfield vary from the $defaults
	 *
	 * @param array|null $defaults Default settings Default settings or omit to pull automatically
	 * @param string $cacheKey Optionally cache with this key
	 * @return array
	 *
	 */
	public function getSettings($defaults = null, $cacheKey = '') {

		$inputfield = $this->inputfield;
		
		if($cacheKey && isset(self::$caches['settings'][$cacheKey])) return self::$caches['settings'][$cacheKey];
		if($defaults === null) $defaults = $this->getDefaults();

		$settings = array();
		$features = $inputfield->get('features');
		
		if(!is_array($features)) $features = $this->inputfield->features;

		$useInline = in_array('inline', $features);

		foreach($defaults as $name => $defaultValue) {
			if($name === 'menubar') {
				$value = in_array($name, $features);
			} else if($name === 'statusbar') {
				$value = $useInline ? in_array($name, $features) : true;
			} else if($name === 'browser_spellcheck') {
				$value = in_array('spellcheck', $features);
			} else if($name === 'toolbar') {
				$value = in_array($name, $features) ? $inputfield->get($name) : '';
			} else if($name === 'toolbar_sticky') {
				$value = in_array('stickybars', $features);
			} else if($name === 'content_css') {
				$value = $inputfield->get($name);
				if($value === 'custom') {
					$value = $inputfield->get('content_css_url');
					if(empty($value)) continue;
				}
			} else if($name === 'directionality') {
				$value = $this->inputfield->getDirectionality();
			} else if($name === 'style_formats') {
				$value = $this->getStyleFormats($defaults);
			} else if($name === 'block_formats') {
				$value = $this->getBlockFormats();
			} else {
				$value = $inputfield->get($name);
			}
			if($value !== null && $value != $defaultValue) {
				$settings[$name] = $value;
			}
		}

		$this->applySkin($settings);
		$this->applyPlugins($settings, $defaults);

		if(isset($defaults['style_formats'])) {
			$styleFormatsCSS = $inputfield->get('styleFormatsCSS');
			if($styleFormatsCSS) {
				$this->applyStyleFormatsCSS($styleFormatsCSS, $settings);
			}
		}

		if($cacheKey) self::$caches['settings'][$cacheKey] = $settings;

		return $settings;
	}

	/**
	 * Default settings for ProcessWire.config.InputfieldTinyMCE
	 *
	 * This should have no field-specific settings (no dynamic values)
	 *
	 * @return array
	 *
	 */
	public function getDefaults() {
		
		if(!empty(self::$caches['defaults'])) return self::$caches['defaults'];

		$config = $this->wire()->config;
		$root = $config->urls->root;
		$url = $config->urls($this->inputfield);
		$tools = $this->inputfield->tools();

		// root relative, i.e. '/site/modules/InputfieldTinyMCE/'
		$url = substr($url, strlen($root)-1);
		$alignClasses = $this->getAlignClasses();

		// selector of elements that can be used with align commands

		$replacements = array(
			'{url}' => $url,
			'{alignleft}' => $alignClasses['left'], 
			'{aligncenter}' => $alignClasses['center'], 
			'{alignright}' => $alignClasses['right'], 
			'{alignfull}' => $alignClasses['full'],
		);
		
		$json = file_get_contents(__DIR__ . '/defaults.json');
		$json = str_replace(array_keys($replacements), array_values($replacements), $json);
		$defaults = $tools->jsonDecode($json, 'defaults.json');

		$file = $this->inputfield->defaultsFile;
		if($file) {
			$file = $url . ltrim($file, '/');
			$data = $tools->jsonDecodeFile($file, 'default settings file for module');
			if(is_array($data) && !empty($data)) $defaults = array_merge($defaults, $data);
		}
		
		$json = $this->inputfield->defaultsJSON;
		if($json) {
			$data = $tools->jsonDecode($json, 'defaults JSON module setting'); 
			if(is_array($data) && !empty($data)) $defaults = array_merge($defaults, $data);
		}
		
		$languageSettings = $this->getLanguageSettings();
		if(!empty($languageSettings)) $defaults = array_merge($defaults, $languageSettings);
		
		self::$caches['defaults'] = $defaults;
		
		return $defaults;
	}

	/**
	 * Apply plugins settings
	 *
	 * @param array $settings
	 * @param array $defaults
	 *
	 */
	protected function applyPlugins( array &$settings, array $defaults) {
		$extPlugins = $this->inputfield->get('extPlugins');

		if(!empty($extPlugins)) {
			$value = $defaults['external_plugins'];
			foreach($extPlugins as $url) {
				$name = basename($url, '.js');
				$value[$name] = $url;
			}
			$settings['external_plugins'] = $value;
		}

		if(isset($defaults['plugins'])) {
			$plugins = $this->inputfield->get('plugins');
			if(empty($plugins) && !empty($defaults['plugins'])) $plugins = $defaults['plugins'];
			if(!is_array($plugins)) $plugins = explode(' ', $plugins);
			if(!in_array('pwlink', $plugins)) {
				unset($settings['external_plugins']['pwlink']);
				$settings['menu']['insert']['items'] = str_replace('pwlink', 'link', $settings['menu']['insert']['items']);
			}
			if(!in_array('pwimage', $plugins)) {
				unset($settings['external_plugins']['pwimage']);
				$settings['menu']['insert']['items'] = str_replace('pwimage', 'image', $settings['menu']['insert']['items']);
			}
			$settings['plugins'] = implode(' ', $plugins);
			if($settings['plugins'] === $defaults['plugins']) unset($settings['plugins']);
		}
	}

	/**
	 * Apply skin or skin_url directly to given settings/defaults
	 * 
	 * @param array $settings
	 * 
	 */
	protected function applySkin(&$settings) {
		$skin = $this->inputfield->skin;
		if($skin === 'custom') {
			$skinUrl = rtrim($this->inputfield->skin_url, '/');
			if(strlen($skinUrl)) {
				if(strpos($skinUrl, '//') === false) {
					$skinUrl = $this->wire()->config->urls->root . ltrim($skinUrl, '/');
				}
				$this->message($skinUrl);
				$settings['skin_url'] = $skinUrl;
				unset($settings['skin']); 
			}
		} else {
			$settings['skin'] = $skin;
			unset($settings['skin_url']);
		}
	}

	/**
	 * Get image alignment classes
	 * 
	 * @return array
	 * 
	 */
	public function getAlignClasses() {
		if(empty(self::$caches['alignClasses'])) {
			$data = $this->wire()->modules->getModuleConfigData('ProcessPageEditImageSelect');
			self::$caches['alignClasses'] = array(
				'left' => (empty($data['alignLeftClass']) ? 'align_left' : $data['alignLeftClass']),
				'right' => (empty($data['alignRightClass']) ? 'align_right' : $data['alignRightClass']),
				'center' => (empty($data['alignCenterClass']) ? 'align_center' : $data['alignCenterClass']),
				'full' => 'align_full', 
			);
		}
		return self::$caches['alignClasses'];
	}

	/**
	 * Get style_formats
	 * 
	 * @param array $defaults
	 * @return array|mixed
	 * 
	 */
	protected function getStyleFormats(array $defaults) {
		
		/*
		'style_formats' => array(
			array(
				'title' => 'Headings',
				'items' => array(
					array('title' => 'Heading 1', 'format' => 'h1'),
					array('title' => 'Heading 2', 'format' => 'h2'),
					array('title' => 'Heading 3', 'format' => 'h3'),
					array('title' => 'Heading 4', 'format' => 'h4'),
					array('title' => 'Heading 5', 'format' => 'h5'),
					array('title' => 'Heading 6', 'format' => 'h6')
				)
			),
		*/

		$headlines = $this->inputfield->headlines;
		$headlines = array_flip($headlines);
		
		$formats = $defaults['style_formats'];
		
		foreach($formats as $key => $format) {
			if(!is_array($format)) continue;
			if($format['title'] === 'Headings') {
				foreach($format['items'] as $k => $item) {
					if(empty($item['format'])) continue;
					$tag = $item['format'];
					if(!isset($headlines[$tag])) unset($formats[$key]['items'][$k]);
				}
				$formats[$key]['items'] = array_values($formats[$key]['items']);
				break;
			}	
		}
		
		return $formats;
	}

	/**
	 * Merge the given style formats
	 * 
	 * @param array $styleFormats
	 * @param array $addFormats
	 * @return array
	 * 
	 */
	public function mergeStyleFormats(array $styleFormats, array $addFormats) {
		$a = array();
		foreach($styleFormats as $value) {
			if(empty($value['title'])) continue;
			$title = $value['title'];
			$a[$title] = $value; 
		}
		$styleFormats = $a; 
		foreach($addFormats as $value) {
			if(empty($value['title'])) continue;
			$title = $value['title'];
			if(isset($styleFormats[$title])) {
				if(isset($styleFormats[$title]['items'])) {
					if(isset($value['items'])) {
						$styleFormats[$title]['items'] = array_merge($styleFormats[$title]['items'], $value['items']);
					}
				} else {
					$styleFormats[$title] = array(
						'title' => $title, 
						'items' => $value,
					);
				}
			} else {
				$styleFormats[$title] = $value; 
			}
		}
		return array_values($styleFormats);
	}

	/**
	 * Get block_formats
	 * 
	 * @return string
	 * 
	 */
	protected function getBlockFormats() {
		// 'block_formats' => 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6;',
		$values = array('Paragraph=p;');
		$headlines = $this->inputfield->get('headlines');
		foreach($headlines as $h) {
			$n = ltrim($h, 'h');
			$values[] = "Headline $n=$h;";
		}
		return implode(' ', $values);
	}


	/**
	 * Get settings from custom settings file
	 * 
	 * @return array
	 * 
	 */
	protected function getFromSettingsFile() {
		$file = $this->inputfield->get('settingsFile');
		if(empty($file)) return array();
		$file = $this->wire()->config->paths->root . ltrim($file, '/'); 
		return $this->inputfield->tools()->jsonDecodeFile($file, 'settingsFile');	
	}

	/**
	 * Get settings from custom JSON
	 *
	 * @return array
	 * 
	 */
	protected function getFromSettingsJSON() {
		$json = trim((string) $this->inputfield->get('settingsJSON'));
		if(empty($json)) return array();
		return $this->inputfield->tools()->jsonDecode($json, 'settingsJSON');
	}

	/**
	 * Get content_css URL
	 * 
	 * @param string $content_css
	 * @return string
	 * 
	 */
	public function getContentCssUrl($content_css = '') {
		
		$config = $this->wire()->config;
		$rootUrl = $config->urls->root;
		$defaultUrl = $config->urls($this->inputfield) . 'content_css/wire.css';
		
		if(empty($content_css)) {
			$content_css = $this->inputfield->content_css;
		}
		
		if($content_css === 'wire' || empty($content_css)) {
			// default
			$url = $defaultUrl;

		} else if(strpos($content_css, '/')) {
			// custom file
			$url = $rootUrl . ltrim($content_css, '/');

		} else if($content_css === 'custom') {
			// custom file (alternate/fallback)
			$content_css_url = $this->inputfield->content_css_url;
			if(empty($content_css_url) || strpos($content_css_url, '/') === false) {
				$url = $defaultUrl;
			} else {
				$url = $rootUrl . ltrim($content_css_url, '/');
			}

		} else if($content_css) {
			// defined
			$content_css = basename($content_css, '.css');
			$url = $config->urls($this->inputfield) . "content_css/$content_css.css";
			
		} else {
			$url = $defaultUrl;
		}

		return $url;
	}


	/**
	 * Prepare given settings ready for output
	 *
	 * This converts relative URLs to absolute, etc.
	 *
	 * @param array $settings
	 * @return array
	 *
	 */
	public function prepareSettingsForOutput(array $settings) {
		$config = $this->wire()->config;
		$rootUrl = $config->urls->root;
		//$inline = $this->inputfield->inlineMode > 0;

		/*
		if($inline) {
			// content_css not loaded here
			//$settings['content_css'] = '';
		*/
			
		if(isset($settings['content_css'])) {
			// convert content_css setting to URL
			$settings['content_css'] = $this->getContentCssUrl($settings['content_css']);
		}

		if(!empty($settings['external_plugins'])) {
			foreach($settings['external_plugins'] as $name => $url) {
				$settings['external_plugins'][$name] = $rootUrl . ltrim($url, '/');
			}
		}
		if(isset($settings['height'])) {
			$settings['height'] = "$settings[height]px";
		}
	
		if(isset($settings['toolbar'])) {
			$splitTools = array('styles', 'blocks'); 
			foreach($splitTools as $name) {
				$settings['toolbar'] = str_replace("$name ", "$name | ", $settings['toolbar']); 
			}
		}
	
		/*
		if(isset($settings['plugins']) && is_array($settings['plugins'])) {
			$settings['plugins'] = implode(' ', $settings['plugins']); 
		}
		*/

		return $settings;
	}

	/**
	 * Get language pack code
	 * 
	 * @return string
	 * 
	 */
	public function getLanguagePackCode() {
	
		$default = 'en_US';
		$languages = $this->wire()->languages;
		$path = __DIR__ . "/langs/";
		
		if(!$languages) return $default;
		
		$language = $this->wire()->user->language;
		
		// attempt to get from module setting
		$value = $this->inputfield->get("lang_$language->name");
		if($value) return $value;
	
		// attempt to get from non-default language name
		if(!$language->isDefault() && is_file("$path$language->name.js")) {
			return $language->name;
		}
	
		// attempt to get from admin theme
		$value = $this->wire()->adminTheme->_('en');
		if($value !== 'en' && is_file("$path$value.js")) return $value;

		$value = $languages->getLocale();
	
		// attempt to get from locale setting
		if($value !== 'C') {
			if(strpos($value, '.')) list($value,) = explode('.', $value, 2);
			if(is_file("$path$value.js")) return $value;
			if(strpos($value, '_')) {
				list($value,) = explode('_', $value, 2);
				if(is_file("$path$value.js")) return $value;
			}
		}
	
		// attempt to get from CKEditor static translation
		$textdomain = '/wire/modules/Inputfield/InputfieldCKEditor/InputfieldCKEditor.module';
		if(is_file($this->wire()->config->urls->root . ltrim($textdomain, '/'))) {
			$value = _x('en', 'language-pack', $textdomain);
			if($value !== 'en' && is_file("$path$value.js")) return $value;

			$value = _x('en', 'language-code', $textdomain);
			if($value !== 'en' && is_file("$path$value.js")) return $value;
		}

		return $default;
	}

	/**
	 * Get language pack settings
	 *
	 * @return array
	 * 
	 */
	public function getLanguageSettings() {
		if(!$this->wire()->languages) return array();
		$language = $this->wire()->user->language;
		if(isset(self::$caches['langSettings'][$language->id])) {
			return self::$caches['langSettings'][$language->id];
		}
		$code = $this->getLanguagePackCode();
		if($code === 'en_US') {
			$value = array();
		} else {
			$value = array(
				'language' => $code, 
				'language_url' => $this->wire()->config->urls($this->inputfield) . "langs/$code.js"
			);
		}
		self::$caches['langSettings'][$language->id] = $value;
		return $value;
	}

	/**
	 * Apply 'add_*' settings in $addSettings, plus merge all $addSettings into given $settings 
	 * 
	 * This updates the $settings and $addSettings variables directly
	 * 
	 * @param array $settings
	 * @param array $addSettings
	 * @param array $defaults
	 * 
	 */
	protected function applyAddSettings(array &$settings, array &$addSettings, array $defaults) {
	
		// apply add_style_formats when present
		if(isset($addSettings['add_style_formats'])) {
			$styleFormats = isset($settings['style_formats']) ? $settings['style_formats'] : $defaults['style_formats'];
			$settings['style_formats'] = $this->mergeStyleFormats($styleFormats, $addSettings['add_style_formats']);
			unset($addSettings['add_style_formats']);
		}
	
		// find other add_* properties, i.e. 'add_formats', 'add_invalid_styles', 'add_plugins'
		// these append rather than replace, i.e. 'add_formats' appends to 'formats'
		foreach($addSettings as $key => $addValue) {
			if(strpos($key, 'add_') !== 0) continue;
			list(,$name) = explode('add_', $key, 2);
			unset($addSettings[$key]); 
			if(isset($settings[$name])) {
				// present in settings
				$value = $settings[$name];
			} else if(isset($defaults[$name])) {
				// present in defaults
				$value = $defaults[$name];
			} else {
				// not present, add it to settings
				$addSettings[$name] = $addValue;
				continue;
			}
			if(is_string($value) && is_string($addValue)) {
				$value .= " $addValue";
			} else if(is_array($addValue) && is_array($value)) {
				foreach($addValue as $k => $v) {
					if(is_int($k)) {
						// append
						$value[] = $v;
					} else {
						// append or replace
						$value[$k] = $v;
					}
				}
			} else {
				$value = $addValue;
			}
			$addSettings[$name] = $value;
		}
	
		$settings = array_merge($settings, $addSettings);
	}

	/**
	 * Determine which settings go where and populate or output
	 * 
	 */
	public function renderReadySettings() {
	
		$config = $this->wire()->config;
		$adminTheme = $this->wire()->adminTheme;
		$inputfield = $this->inputfield;
		$configName = $inputfield->getConfigName();
		
		// default settings
		$defaults = $this->getDefaults();

		// settings defined in custom JSON (file or input)
		$addSettings = array_merge($this->getFromSettingsFile(), $this->getFromSettingsJSON());

		if($configName) {
			$js = $config->js($inputfield->className());

			// get settings that differ between field and defaults, then set to new named config
			$diffSettings = $this->getSettings($defaults, $configName);
			$mergedSettings = array_merge($defaults, $diffSettings);
			$contentStyle = isset($mergedSettings['content_style']) ? $mergedSettings['content_style'] : '';

			if(count($addSettings)) {
				// merges $addSettings into $diffSettings
				$this->applyAddSettings($diffSettings, $addSettings, $defaults);
			}

			if(!isset($js['settings'][$configName])) {
				$js['settings'][$configName] = $this->prepareSettingsForOutput($diffSettings);
				$config->js($inputfield->className(), $js);
			}

			// get settings that will go in data-settings attribute 
			// remove settings that cannot be set for field/template context
			unset($mergedSettings['style_formats'], $mergedSettings['content_style'], $mergedSettings['content_css']); 
			$dataSettings = $this->getSettings($mergedSettings);

		} else {
			// no configName in use, data-settings attribute will hold all non-default settings
			$dataSettings = $this->getSettings($defaults);
			$contentStyle = isset($dataSettings['content_style']) ? $dataSettings['content_style'] : '';
			if(count($addSettings)) {
				$this->applyAddSettings($dataSettings, $addSettings, $defaults);
			}
		}

		if($inputfield->inlineMode) {
			if($inputfield->inlineMode < 2) unset($dataSettings['height']);
			$dataSettings['inline'] = true;
			if($contentStyle && $adminTheme) {
				$cssName = $configName;
				if(empty($cssName)) {
					$cssName = substr(md5($contentStyle), 0, 4) . strlen($contentStyle);
					$inputfield->addClass("tmcei-$cssName", 'wrapClass');
				}
				if(!isset(self::$caches['renderReadyInline'][$cssName])) {
					// inline mode content_style settings, ensure they are visible before inline init
					$ns = ".tmcei-$cssName .mce-content-body ";
					$contentStyle = $ns . str_replace('}', "} $ns", $contentStyle) . '{}';
					$adminTheme->addExtraMarkup('head', "<style>$contentStyle</style>");
					self::$caches['renderReadyInline'][$cssName] = $cssName;
				}
			}
		}
	
		$dataSettings = count($dataSettings) ? $this->prepareSettingsForOutput($dataSettings) : array();
		
		$features = array();
		if($inputfield->useFeature('imgUpload')) $features[] = 'imgUpload';
		if($inputfield->useFeature('imgResize')) $features[] = 'imgResize';
		
		$inputfield->wrapAttr('data-configName', $configName);
		$inputfield->wrapAttr('data-settings', json_encode($dataSettings));
		$inputfield->wrapAttr('data-features', implode(',', $features));
	}

	/**
	 * Add CSS that converts to style_formats and content_style
	 * 
	 * Easier-to-use alternative to the importcss plugin
	 * 
	 * @param string $css From the styleFormatsCSS setting
	 * @param array $settings
	 * 
	 */
	protected function applyStyleFormatsCSS($css, array &$settings) {

		$contentStyle = ''; // output for content_style
		$css = trim(str_replace('}', "}\n", $css));
		$css = preg_replace('!\s*([{;:]|/\*)\s*!s', '\1', $css);
		$lines = explode("\n", $css);
		$parents = array();
		$formats = array(
			/*
			'Headings' => array(), 
			'Blocks' => array(),
			'Inline' => array(),
			'Align' => array(),
			'Other' => array(), // converts to root level (no parent)
			*/
		);
		
		foreach($lines as $key => $line) {
			$line = trim($line);
			if(empty($line)) {
				unset($lines[$key]);
				continue;
			}
			$lines[$key] = $line;
			if(strpos($line, '/*') !== false) {
				list($a, $b) = explode('/*', $line, 2);
				list($title, $d) = explode('*/', $b, 2);
				$css = str_replace("/*$title*/", "", $css);
				$line = $a . $d;
				$title = trim($title);
			} else {
				$title = '';
			}
			if(strpos($line, '{') === false) continue;
			list($selector, $styles) = explode('{', $line, 2);
			list($styles,) = explode('}', $styles, 2);
			$selector = trim($selector);
			if(strpos($selector, '#') === 0) {
				list($parent, $selector) = explode(' ', $selector, 2);
				$selector = trim($selector);
				$parent = ucfirst(strtolower(ltrim($parent, '#')));
			} else {
				$parent = 'Other';
			}
			$parents[$parent] = "#$parent ";
			if(strpos($selector, '.') !== false) {
				list($element, $class) = explode('.', $selector, 2);
				$class = str_replace('.', ' ', $class);
				$stylesArray = array();
				$contentStyle .= "$element.$class { $styles } ";
			} else {
				$element = $selector;
				$class = '';
				$stylesArray = array();
				foreach(explode(';', $styles) as $style) {
					if(strpos($style, ':') === false) continue;
					list($k, $v) = explode(':', $style);
					$stylesArray[trim($k)] = trim($v);
				}
			}
			if(empty($element)) $element = '*';
			if(!isset($formats[$parent])) $formats[$parent] = array();
			
			$format = array('title' => ($title ? $title : $selector));

			if(stripos("/$element/", self::$inlines) !== false) {
				$format['inline'] = $element;
			} else if(strpos("/$element/", self::$blocks) !== false) {
				$format['block'] = $element;
			} else {
				$format['selector'] = $element;
			}
			if($class) {
				$format['classes'] = $class;
			} else if(count($stylesArray)) {
				$format['styles'] = $stylesArray; 
			}
			$formats[$parent][] = $format;
		}

		$styleFormats = array();
		
		foreach($formats as $parent => $format) {
			if($parent === 'Other') {
				$styleFormats[$parent] = $format;
			} else {
				if(!isset($styleFormats[$parent])) $styleFormats[$parent] = array(
					'title' => $parent,
					'items' => $format,
				);
			}
		}
		
		$other = isset($styleFormats['Other']) ? $styleFormats['Other'] : array();
		unset($styleFormats['Other']);

		$styleFormats = array_values($styleFormats);
		if(count($other)) $styleFormats = array_merge($styleFormats, $other);
	
		// $css = trim(str_replace(array(';}', "\n"), array('}', ''), $css));
		// $css = str_ireplace(array_values($parents), '', $css);
		
		// add to settings
		if(isset($settings['style_formats'])) {
			$settings['style_formats'] = $this->mergeStyleFormats($settings['style_formats'], $styleFormats); 
		} else {
			$settings['style_formats'] = $styleFormats;
		}
		
		if(isset($settings['content_style'])) {
			$settings['content_style'] .= $contentStyle;
		} else {
			$settings['content_style'] = $contentStyle;
		}
	}
	
}