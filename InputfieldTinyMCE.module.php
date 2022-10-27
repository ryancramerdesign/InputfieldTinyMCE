<?php namespace ProcessWire;

/**
 * InputfieldTinyMCE
 * 
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 * 
 * TinyMCE 6.x, Copyright (c) 2022 Ephox Corporation DBA Tiny Technologies, Inc.
 * https://www.tiny.cloud/docs/tinymce/6/
 *
 * TinyMCE settings (these are also Field settings)
 * ------------------------------------------------
 * @property string $plugins Space-separated string of plugins to enable
 * @property string $toolbar Space-separated string of tools to show in toolbar
 * @property string $contextmenu Space-separated string of tools to show in context menu
 * @property string $removed_menuitems Space-separated string of tools to remove from menubar
 * @property string $invalid_styles Space-separated string of invalid inline styles
 * @property int $height Height of editor in pixels
 *
 * Field/Inputfield settings
 * -------------------------
 * @property int $inlineMode Use inline mode? 0=Regular editor, 1=Inline editor, 2=Fixed height inline editor
 * @property array $toggles Markup toggles, see self::toggle* constants
 * @property array $features General features: toolbar, menubar, statusbar, stickybars, spellcheck, purifier, imgUpload, imgResize
 * @property array $headlines Allowed headline types
 * @property string $settingsFile Location of optional custom-settings.json settings file (URL relative to PW root URL)
 * @property string $settingsField Alternate field to inherit settings from rather than configure settings with this instance.
 * @property string $settingsJSON JSON with custom settings that override the defaults
 * @property string $styleFormatsCSS Style formats as CSS to parse and apply to style_formats and content_style
 * @property array $extPlugins Additional plugins to enable for this field (URL paths from customPluginOptions) 
 * 
 * Module settings
 * ---------------
 * @property string $content_css Basename of content CSS file to use or "custom" to use custom URL (default='wire')
 * @property string $content_css_url Applies only if $content_css has value "custom"
 * @property string $skin
 * @property string $skin_url
 * @property string $extPluginOpts Newline separated URL paths (relative to PW root) of extra plugin .js files
 * @property string $defaultsFile Location of optional defaults.json file that merges with defaults.json (URL relative to PW root URL)
 * @property string $defaultsJSON JSON that merges with the defaults.json for all instances
 * There are also `$lang_name=packname` settings in multi-lang sites where "name" is lang name and "packname" is lang pack name
 * 
 * Runtime settings
 * ----------------
 * @property-read bool $readonly Automatically set during renderValue mode
 * @property array $external_plugins URLs of external plugins, this is also a TinyMCE setting
 * 
 * 
 */
class InputfieldTinyMCE extends InputfieldTextarea implements ConfigurableModule {
	
	/**
	 * Get module info
	 *
	 * @return array
	 *
	 */
	public static function getModuleInfo() {
		return array(
			'title' => 'TinyMCE',
			'summary' => 'TinyMCE rich text editor version ' . self::mceVersion . '.',
			'version' => 601,
			'icon' => 'keyboard-o',
			'requires' => 'MarkupHTMLPurifier',
		);
	}
	
	const mceVersion = '6.2.0';
	
	const toggleCleanDiv = 2; // remove <div>s
	const toggleCleanP = 4; // remove empty <p> tags	
	const toggleCleanNbsp = 8; // remove &nbsp; entities

	/**
	 * Have editor scripts loaded in this request?
	 * 
	 * @var bool 
	 * 
	 */
	static protected $loaded = false;

	/**
	 * @var MarkupHTMLPurifier|null 
	 * 
	 */
	static protected $purifier = null;
	
	/**
	 * Name of current JS config key
	 *
	 */
	protected $configName = 'default';

	/**
	 * @var array 
	 * 
	 */
	protected $helpers = array();

	/**
	 * TinyMCE setting names that are configurable with each instance of this module
	 * 
	 * @var string[] 
	 * 
	 */
	protected $mceSettingNames = array(
		'skin',
		'height',
		'plugins',
		'toolbar',
		'menubar', 
		'statusbar',
		'contextmenu',
		'removed_menuitems',
		'external_plugins',
		'invalid_styles',
		'readonly',
		'content_css',
		'content_css_url', // used when content_css=="custom", not part of tinyMCE
		'external_plugins',
		'skin_url', 
	);

	/**
	 * Names of all field settings (set in init)
	 * 
	 * This is so that we can determine what settings to pull from a $settingsField
	 * 
	 * @var array 
	 * 
	 */
	protected $fieldSettingNames = array();

	/**
	 * Available options for 'features' setting
	 * 
	 * @var string[] 
	 * 
	 */
	protected $featureNames = array(
		'toolbar',
		'menubar',
		'statusbar',
		'stickybars',
		'spellcheck',
		'purifier',
		'imgUpload',
		'imgResize',
	);

	/**
	 * @var bool 
	 * 
	 */
	protected $configurable = true;

	/**
	 * Construct
	 */
	public function __construct() {
		// module settings
		$this->data(array(
			'skin' => 'oxide',
			'content_css' => 'wire',
			'content_css_url' => '', 
			'invalid_styles' => '',
			'defaultsFile' => '', 
			'defaultsJSON' => '', 
			'extPluginOptions' => '', 
		));
		parent::__construct();
	}
	
	/**
	 * Init Inputfield
	 *
	 */
	public function init() {
		parent::init();
	
		$this->attr('rows', 15);
	
		// field settings
		$data = array(
			'contentType' => FieldtypeTextarea::contentTypeHTML,
			'inlineMode' => 0,
			'features' => $this->featureNames,
			'headlines' => array('h1','h2','h3','h4','h5','h5','h6'),
			'settingsFile' => '', 
			'settingsField' => '', 
			'settingsJSON' => '', 
			'styleFormatsCSS' => '', 
			'extPlugins' => array(),
			'toggles' => array(
				self::toggleCleanDiv,
				self::toggleCleanNbsp,
				self::toggleCleanP, 
			),
		);
		
		$this->data($data);
		$this->fieldSettingNames = array_keys($data);
	
		// module settings
		$defaults = $this->settings()->getDefaults();
		$settings = array();
		
		foreach($this->mceSettingNames as $key) {
			// skip over module-wide settings that match TinyMCE setting names
			if($key === 'skin' || $key === 'skin_url') continue;
			if($key === 'content_css' || $key === 'content_css_url') continue;
			$settings[$key] = $defaults[$key]; 
		}
		
		$this->data($settings);
	}

	/**
	 * Use the named feature?
	 * 
	 * @param string $name
	 * @return bool
	 * 
	 */
	public function useFeature($name) {
		if($name === 'inline') return $this->inlineMode > 0;
		return in_array($name, $this->features);
	}
	
	/**
	 * Return path or URL to TinyMCE files
	 * 
	 * @param bool $getUrl
	 * @return string
	 * 
	 */
	public function mcePath($getUrl = false) {
		$config = $this->wire()->config;
		$path = ($getUrl ? $config->urls($this) : __DIR__ . '/');
		return $path . 'tinymce-' . self::mceVersion . '/';
	}

	/**
	 * Set configuration name used to store settings in ProcessWire.config JS
	 * 
	 * i.e. ProcessWire.config.InputfieldTinyMCE.settings.[configName].[settingName]
	 * 
	 * @param string $configName
	 * @return $this
	 * 
	 */
	public function setConfigName($configName) {
		$this->configName = $configName;
		return $this;
	}

	/**
	 * Get configuration name used to store settings in ProcessWire.config JS
	 * 
	 * i.e. ProcessWire.config.InputfieldTinyMCE.settings.[configName].[settingName]
	 * 
	 * @return string
	 * 
	 */
	public function getConfigName() {
		return $this->configName;
	}

	/**
	 * Get or set configurable state
	 * 
	 * - True if Inputfield is configurable (default state). 
	 * - False if it is required that another field be used ($settingsField) to pull settings from. 
	 * - Note this is completely unrelated to the $configName property. 
	 * 
	 * @param bool $set
	 * @return bool
	 * 
	 */
	public function configurable($set = null) {
		if(is_bool($set)) $this->configurable = $set;
		return $this->configurable;
	}

	/**
	 * Get
	 * 
	 * @param $key
	 * @return array|mixed|string|null
	 * 
	 */
	public function get($key) {
		if($key === 'configName') return $this->configName;
		return parent::get($key);
	}

	/**
	 * Set
	 * 
	 * @param $key
	 * @param $value
	 * @return self
	 * 
	 */
	public function set($key, $value) {
		
		if($key === 'toolbar') {
			if(strpos($value, ',') !== false) {
				// $value = $this->configHelper()->ckeToMceToolbar($value); // convert CKE toolbar
				return $this; // ignore CKE toolbar (which has commas in it)
			}
			$value = $this->tools()->sanitizeNames($value);
			
		} else if($key === 'plugins' || $key === 'contextmenu' || $key === 'removed_menuitems') {
			$value = $this->tools()->sanitizeNames($value);
			
		} else if($key === 'configName') {
			return $this->setConfigName($value);
		}
		
		return parent::set($key, $value);
	}
	
	/**
	 * Render ready that only needs one call for entire request
	 * 
	 */
	protected function renderReadyOnce() {
		
		$modules = $this->wire()->modules;
		$adminTheme = $this->wire()->adminTheme;
		
		$class = $this->className();
		$config = $this->wire()->config;
		$mceUrl = $this->mcePath(true);
		$skin = $this->skin;
		$addStyles = array();
		
		$config->scripts->add($mceUrl . 'tinymce.min.js');
		
		$jQueryUI = $modules->get('JqueryUI'); /** @var JqueryUI $jQueryUI */
		$jQueryUI->use('modal');
		
		if(strpos($skin, 'dark') !== false && strpos($this->content_css, 'dark') === false) {
			// ensure some menubar/toolbar labels are not black-on-black in dark skin + light content_css
			// this was necessary as of TinyMCE 6.2.0
			$addStyles[] = "body .tox-collection__item-label > *:not(code):not(pre) { color: #eee !important; }";
		}
	
		if($skin && $skin != 'custom' && $adminTheme) {
			// make dialogs use PW native colors for buttons (without having to make a custom skin for it)
			$buttonSelector = ".tox-dialog .tox-button:not(.tox-button--secondary):not(.tox-button--icon)";
			$addStyles[] = "$buttonSelector { background-color: #3eb998; border-color: #3eb998; }";
			$addStyles[] = "$buttonSelector:hover { background-color: #e83561; border-color: #e83561; }";
		}
		
		if(count($addStyles) && $adminTheme) {
			// note: using a body class (rather than <style>) interferes with TinyMCE inline mode
			// making it leave toolbar/menubar active even when moving out of the field
			$adminTheme->addExtraMarkup('head', '<style>' . implode(' ', $addStyles) . '</style>'); 
		}
		
		$js = array(
			'settings' => array(
				'default' => $this->settings()->prepareSettingsForOutput($this->settings()->getDefaults())
			),
			'labels' => array(
				// translatable labels for pwimage and pwlink plugins
				'selectImage' => $this->_('Select image'),
				'editImage' => $this->_('Edit image'),
				'captionText' => $this->_('Your caption text here'),
				'savingImage' => $this->_('Saving image'),
				'cancel' => $this->_('Cancel'),
				'insertImage' => $this->_('Insert image'),
				'selectAnotherImage' => $this->_('Select another'),
				'insertLink' => $this->_('Insert link'),
				'editLink' => $this->_('Edit link'),
			),
			'pwlink' => array(
				// settings specific to pwlink plugin
				'classOptions' => $this->tools()->linkConfig('classOptions')
			),
		);
	
		$config->js($class, $js);
	}


	/**
	 * Render ready
	 *
	 * @param Inputfield|null $parent
	 * @param bool $renderValueMode
	 * @return bool
	 *
	 */
	public function renderReady(Inputfield $parent = null, $renderValueMode = false) {
		
		if(!self::$loaded) {
			$this->renderReadyOnce();
			self::$loaded = true;
		}

		$settingsField = $this->settingsField;
		
		if($settingsField) {
			$settingsField = $this->settings()->applySettingsField($settingsField);
		}

		$replaceTools = array();
		$upload = $this->useFeature('imgUpload');
		$imageField = $upload ? $this->tools()->getImageField() : null;
		$field = $settingsField instanceof Field ? $settingsField : $this->hasField;
		
		if($this->inlineMode) {
			$cssFile = $this->settings()->getContentCssUrl();
			$this->wire()->config->styles->add($cssFile);
		}

		if($imageField) {
			// custom attributes for images
			$this->addClass('InputfieldHasUpload', 'wrapClass');
			$this->wrapAttr('data-upload-page', $this->hasPage->id);
			$this->wrapAttr('data-upload-field', $imageField->name);
			
		} else if(!$this->hasPage) {
			// pwimage plugin requires a page editor
			$replaceTools['pwimage'] = 'image';
			if($this->wire()->page->template->name !== 'admin') {
				// pwlink requires admin
				$replaceTools['pwlink'] = 'link';
			}
		}
	
		if(count($replaceTools)) {
			foreach($replaceTools as $find => $replace) {
				$this->plugins = str_replace($find, $replace, $this->plugins);
				$this->toolbar = str_replace($find, $replace, $this->toolbar);
				$this->contextmenu = str_replace($find, $replace, $this->contextmenu);
				$a = $this->external_plugins;
				if(isset($a[$find])) {
					unset($a[$find]);
					$this->external_plugins = $a;
				}
			}
		}
	
		if($field && $field->type instanceof FieldtypeTextarea) {
			/*
			if($field->flags && Field::flagFieldgroupContext) {
				// get field without context
				$field = $this->wire()->fields->get($field->name);
			}
			*/
			if(!$this->configName || $this->configName === 'default') {
				$this->configName = $field->name;
			}
		}
		
		$this->settings()->applyRenderReadySettings();

		return parent::renderReady($parent, $renderValueMode);
	}
	
	/**
	 * Render Inputfield
	 *
	 * @return string
	 *
	 */
	public function ___render() {
		
		if($this->inlineMode && $this->tools()->purifier()) {
			// Inline editor
			$out = $this->renderInline();
		} else {
			// Normal editor
			$out = $this->renderNormal();
		}
		
		return $out;
	}

	/**
	 * Render normal/classic editor
	 * 
	 * @return string
	 * 
	 */
	protected function renderNormal() {
		$id = $this->attr('id');
		$script = 'script';
		$this->addClass('InputfieldTinyMCEEditor InputfieldTinyMCENormal');
		$out = parent::___render();
		$js = "InputfieldTinyMCE.init('#$id'); ";
		$out .= "<$script>$js</$script>";
		return $out;
	}

	/**
	 * Render inline editor
	 * 
	 * @return string
	 * 
	 */
	protected function renderInline() {
		$attrs = $this->getAttributes();
		$inlineFixed = (int) $this->inlineMode > 1; 
		$value = $this->tools()->purifyValue($attrs['value']);
		$rows = (int) $attrs['rows'];
		unset($attrs['value'], $attrs['type'], $attrs['rows']);
		$attrs['class'] = 'InputfieldTinyMCEEditor InputfieldTinyMCEInline mce-content-body';
		$attrs['tabindex'] = '0';
		if($inlineFixed && $rows > 1) {
			$height = ($rows * 2) . 'em';
			$style = isset($attrs['style']) ? $attrs['style'] : '';
			$attrs['style'] = "overflow:auto;height:$height;$style";
		}
		$attrStr = $this->getAttributesString($attrs);
		return "<div $attrStr>$value</div>";
	}

	/**
	 * Render non-editable value
	 * 
	 * @return string
	 * 
	 */
	public function ___renderValue() {
		if(wireInstanceOf($this->wire->process, 'ProcessPageEdit')) {
			$readonly = $this->readonly;
			$this->readonly = true;
			$out = $this->render();
			$this->readonly = $readonly;
		} else {
			$out =
				"<div class='InputfieldTextareaContentTypeHTML InputfieldTinyMCEInline'>" .
					$this->wire()->sanitizer->purify($this->val()) .
				"</div>";
		}
		return $out;
	}

	/**
	 * Process input
	 * 
	 * @param WireInputData $input
	 * @return $this
	 * 
	 */
	public function ___processInput(WireInputData $input) {
		
		$name = $this->attr('name');
		$useName = $name;
		
		if($this->inlineMode) {
			$useName = "Inputfield_$name";
			$this->attr('name', $useName);
		}
		
		$value = $input->$useName;
		$valuePrevious = $this->val();
		
		if($value !== null && $value !== $valuePrevious && !$this->readonly) {
			parent::___processInput($input);
			$value = $this->tools()->purifyValue($value);
			if($value !== $valuePrevious) {
				$this->val($value);
				$this->trackChange('value');
			}
		}
		
		if($this->inlineMode) {
			$this->attr('name', $name);
		}
		
		return $this;
	}

	/**
	 * Update settings to inherit from another field
	 * 
	 * @param string $fieldName Field name or 'fieldName:id' string
	 * @return bool|Field Returns false or field inherited from
	 * 
	 */
	protected function applySettingsField($fieldName) {
		
		$fieldId = 0;
		$error = '';

		if(strpos($fieldName, ':')) {
			list($fieldName, $fieldId) = explode(':', $fieldName);
		} else if(ctype_digit("$fieldName")) {
			$fieldName = (int) $fieldName; // since fields.get also accepts IDs
		}

		$field = $this->wire()->fields->get($fieldName);

		if(!$field) {
			$error = "Cannot find settings field '$fieldName'";
		} else if(!$field->type instanceof FieldtypeTextarea) {
			$error = "Settings field '$fieldName' is not of type FieldtypeTextarea";
			$field = null;
		} else if(!wireInstanceOf($field->get('inputfieldClass'), $this->className())) {
			$error = "Settings field '$fieldName' is not using TinyMCE";
			$field = null;
		}
		
		if(!$field && $fieldId && $fieldName) {
			// try again with field ID only, which won't go recursive again
			return $this->applySettingsField($fieldId); 
		}

		if(!$field) {
			if($error) $this->error($error);
			return false;
		}
	
		// identify settings to apply
		$data = array();
		
		foreach(array_merge($this->mceSettingNames, $this->fieldSettingNames) as $name) {
			$value = $field->get($name);
			if($value !== null) $data[$name] = $value;
		}
	
		// apply settings
		$this->data($data);
		
		return $field;
	}

	/**
	 * Get all configurable setting names
	 * 
	 * @return string[]
	 * 
	 */
	public function getAllSettingNames() {
		return array_merge($this->mceSettingNames, $this->fieldSettingNames);
	}

	/**
	 * Get directionality, either 'ltr' or 'rtl'
	 * 
	 * @return string
	 * 
	 */
	public function getDirectionality() {
		return $this->_x('ltr', 'language-direction'); // change to 'rtl' for right-to-left languages
	}
	
	/**
	 * @return InputfieldTinyMCETools
	 * 
	 */
	public function tools() {
		return $this->helper('tools');
	}
	
	/**
	 * @return InputfieldTinyMCESettings
	 *
	 */
	public function settings() {
		return $this->helper('settings');
	}

	/**
	 * @return InputfieldTinyMCEConfigHelper 
	 * 
	 */
	public function configHelper() {
		return $this->helper('configHelper', 'config');
	}

	/**
	 * Get helper
	 * 
	 * @param string $name
	 * @param string $basename
	 * @return InputfieldTinyMCEConfigHelper|InputfieldTinyMCESettings|InputfieldTinyMCETools
	 * 
	 */
	protected function helper($name, $basename = '') {
		if(empty($this->helpers[$name])) {
			if($basename === '') $basename = $name;
			require_once(__DIR__ . '/' . "$basename.php");
			$class = "\\ProcessWire\\InputfieldTinyMCE" . ucfirst($name);
			$this->helpers[$name] = new $class($this);
		}
		return $this->helpers[$name];
	}
	
	/**
	 * Get Inputfield configuration settings
	 * 
	 * @return InputfieldWrapper
	 * 
	 */
	public function ___getConfigInputfields() {
		$inputfields = parent::___getConfigInputfields();
		$this->configHelper()->getConfigInputfields($inputfields);
		return $inputfields;
	}

	/**
	 * Module config
	 * 
	 * @param InputfieldWrapper $inputfields
	 * 
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$this->configHelper()->getModuleConfigInputfields($inputfields);
	}

}