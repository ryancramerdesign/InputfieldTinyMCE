<?php namespace ProcessWire;

/**
 * InputfieldTinyMCETools
 * 
 * Helper tools for InputfieldTinyMCE module.
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */ 
class InputfieldTinyMCETools extends Wire {

	/**
	 * @var InputfieldTinyMCE 
	 * 
	 */
	protected $inputfield;
	
	static protected $imageFields = array();
	static protected $linkConfig = null;

	/**
	 * @var MarkupHTMLPurifier|null 
	 * 
	 */
	static protected $purifier = null;

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
	 * Sanitize toolbar or plugin names
	 *
	 * @param string|array $value
	 * @return string
	 *
	 */
	public function sanitizeNames($value) {
		if(!is_array($value)) {
			$value = str_replace(array("\n", "\r", "\t"), ' ', $value);
			$value = explode(' ', $value);
		}
		foreach($value as $k => $v) {
			$v = trim($v);
			if((empty($v) || !ctype_alnum($v)) && $v !== '|') {
				unset($value[$k]);
			} else {
				$value[$k] = $v;
			}
		}
		return implode(' ', $value);
	}

	/**
	 * Get field that images can be uploaded to or null if none found
	 *
	 * @return Field|null
	 *
	 */
	public function getImageField() {
		$page = $this->inputfield->hasPage;
		if(!$page || !$page->id) return null;
		$template = $page->template;
		$imageField = null;
		if(isset(self::$imageFields[$template->id])) {
			$imageField = self::$imageFields[$template->id];
		} else {
			foreach($template->fieldgroup as $field) {
				if(!$field->type instanceof FieldtypeImage) continue;
				if($field->get('maxFiles') != 0) continue;
				$imageField = $field;
				self::$imageFields[$template->id] = $field;
				break;
			}
		}
		return $imageField;
	}

	/**
	 * Clean up a value that will be sent to/from the editor
	 *
	 * This is primarily for HTML Purifier
	 *
	 * @param string $value
	 * @return string
	 *
	 */
	public function purifyValue($value) {

		$value = (string) $value;
		if(strpos($value, "\r") !== false) $value = str_replace(array("\r\n", "\r"), "\n", $value);
		if(!strlen($value)) return '';

		$sanitizer = $this->wire()->sanitizer;

		if($this->inputfield->useFeature('purifier') && ($purifier = $this->purifier())) {
			$enableId = stripos($this->inputfield->toolbar, 'anchor') !== false;
			$purifier->set('Attr.AllowedFrameTargets', $this->linkConfig('targetOptions')); // allow links opened in new window/tab
			$purifier->set('Attr.EnableID', $enableId); // for anchor plugin use of id and name attributes
			$value = $purifier->purify($value);
		}

		$value = $this->purifyValueToggles($value);

		// remove UTF-8 line separator characters
		$value = str_replace($sanitizer->unentities('&#8232;'), '', $value);

		return $value;
	}

	/**
	 * Apply markup cleaning toggles
	 *
	 * @param string $value
	 * @return string
	 *
	 */
	public function purifyValueToggles($value) {
		// convert <div> to paragraphs
		$toggles = $this->inputfield->toggles;
		if(!is_array($toggles)) return $value;

		if(in_array(InputfieldTinyMCE::toggleCleanDiv, $toggles) === false && strpos($value, '<div') !== false) {
			$value = preg_replace('{\s*(</?)div[^><]*>\s*}is', '$1' . 'p>', $value);
			while(strpos($value, '<p><p>') !== false) {
				$value = str_replace(array('<p><p>', '</p></p>'), array('<p>', '</p>'), $value);
			}
		}

		// remove gratuitous whitespace
		if(in_array(InputfieldTinyMCE::toggleCleanP, $toggles)) {
			$value = str_replace(array('<p><br /></p>', '<p>&nbsp;</p>', "<p>\xc2\xa0</p>", '<p></p>', '<p> </p>'), '', $value);
		}

		// convert non-breaking space to regular space
		if(in_array(InputfieldTinyMCE::toggleCleanNbsp, $toggles)) {
			$value = str_ireplace('&nbsp;', ' ', $value);
			$value = str_replace("\xc2\xa0",' ', $value);
		}

		return $value;
	}
	
	/**
	 * @return MarkupHTMLPurifier
	 *
	 */
	public function purifier() {
		if(self::$purifier === null) {
			self::$purifier = $this->wire()->modules->get('MarkupHTMLPurifier');
			if(!self::$purifier) {
				$this->error("Unable to load required MarkupHTMLPurifier module");
			}
		}
		return self::$purifier;
	}

	/**
	 * Get config for ProcessPageEditLink module
	 *
	 * @param string $key
	 * @return array|string
	 *
	 */
	public function linkConfig($key = '') {

		if(self::$linkConfig === null) {
			self::$linkConfig = $this->wire()->modules->getModuleConfigData('ProcessPageEditLink');
		}

		$value = &self::$linkConfig;

		if($key === 'targetOptions') {
			$value = isset($value['targetOptions']) ? $value['targetOptions'] : '_blank';
			$value = explode("\n", $value);
			foreach($value as $k => $v) $value[$k] = trim(trim($v), '+');

		} else if($key === 'classOptions') {
			$value = isset($value[$key]) ? $value[$key] : '';
			$options = array();
			foreach(explode("\n", $value) as $option) {
				$options[] = trim($option, '+ ');
			}
			$value = implode(',', $options);
		}

		return $value;
	}

	/**
	 * Decode JSON
	 * 
	 * @param string $json JSON string
	 * @param string $propertyName Name of property JSON is for
	 * @return array
	 * 
	 */
	public function jsonDecode($json, $propertyName) {
		$json = trim((string) $json);
		if(!strlen($json)) return array();
		$a = json_decode($json, true);
		if(!is_array($a)) {
			$this->warning(sprintf(
				$this->_('Error decoding JSON for TinyMCE property "%1$s" - %2$s'),
				$propertyName, json_last_error_msg()
			)); 
			$a = array();
		}
		return $a;	
	}

	/**
	 * Decode JSON file
	 * 
	 * @param string $file
	 * @param string $propertyName
	 * @return array
	 * 
	 */
	public function jsonDecodeFile($file, $propertyName) {
		if(empty($file)) return array();
		if(!file_exists($file)) {
			$this->warning($propertyName . ' - ' . $this->_('File does not exist') . " - $file"); 
			return array();
		}
		if(strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'json') {
			$this->warning($propertyName . ' - ' . $this->_('File extension is not .json') . " - $file");
			return array();
		}
		return $this->jsonDecode(file_get_contents($file), $propertyName);
	}

	/**
	 * Encode array to JSON
	 * 
	 * @param array $a
	 * @param string $propertyName Name of property JSON is for
	 * @return string
	 * 
	 */
	public function jsonEncode($a, $propertyName) {
		if(!is_array($a)) return '';
		$json = json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
		if($json === false) {
			$this->warning(sprintf(
				$this->_('Error encoding JSON for TinyMCE property "%1$s" - %2$s'),
				$propertyName, json_last_error_msg()
			));
			$json = '';
		}
		return (string) $json;
	}

	/**
	 * Get content.css file contents for inline editor output
	 *
	 * @return string
	 * @deprecated
	 *
	public function getContentCssInline() {
		$file = $this->getContentCssFile();
		$css = file_get_contents($file);
		$css = str_replace(array("\n", "\t", "\r", "  "), " ", $css);
		$css = str_replace('}', "}\n", $css);
		while(strpos($css, '  ') !== false) $css = str_replace('  ', ' ', $css);
		$css = str_replace(array(' { ', ' } ', '; ', ': ', ', ', ';}'), array('{', '}', ';', ':', ',', '}'), $css);
		$lines = explode("\n", $css);
		foreach($lines as $key => $line) {
			$line = trim($line);
			if(empty($line)) {
				unset($lines[$key]);
				continue;
			}
			if(strpos($line, '{')) {
				list($a, $b) = explode('{', $line, 2);
				$a = str_replace(',', ',.mce-content-body ', $a);
				$line = $a . '{' . $b;
			}
			if(strpos($line, 'body{') === 0) $line = str_replace('body{', '{', $line);
			$lines[$key] = ".mce-content-body $line";
		}
		return implode("\n", $lines);
	}
	 */

}