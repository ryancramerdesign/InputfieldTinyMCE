<?php namespace ProcessWire;

/**
 * InputfieldTinyMCEFormats
 *
 * Helper for managing TinyMCE style_formats and related settings
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class InputfieldTinyMCEFormats extends InputfieldTinyMCEClass {
	
	/**
	 * HTML5 inline elements that should be "inline" designation in style_formats
	 *
	 * These elements can be inserted from Styles dropdown, if defined in style_formats.
	 *
	 * @var string
	 *
	 */
	static protected $inlines =
		'/a/abbr/acronym/b/bdi/bdo/big/br/button/cite/code/' .
		'/del/dfn/em/i/ins/kbd/label/mark/meter/' .
		'/q/s/samp/small/span/strong/' .
		'/sub/sup/time/u/tt/var/wbr/';

	/**
	 * HTML5 block elements that should use "block" designation in style_formats
	 *
	 * These elements can be inserted from Styles dropdown, if defined in style_formats.
	 *
	 * @var string
	 *
	 */
	static protected $blocks =
		'/address/article/aside/blockquote/dd/details/div/dl/dt/' .
		'/footer/h1/h2/h3/h4/h5/h6/header/hgroup/hr/li/main/nav/ol/p/pre/' .
		'/section/table/ul/';

	/**
	 * HTML5 block or inline elements that should use "selector" designation in style_formats
	 *
	 * These elements (and any others not defined above) cannot be inserted by selection but
	 * existing elements can be applied. For reference only, nothing uses this variable.
	 *
	 * @var string
	 *
	 */
	static protected $inlineBlocks =
		'/fieldset/figcaption/figure/form/dialog/form/' .
		'/audio/canvas/data/datalist/img/iframe/embed/input/map/noscript/object/output/' .
		'/picture/progress/ruby/select/slot/svg/template/textarea/video/';

	/**
	 * Get block_formats
	 *
	 * @return string
	 *
	 */
	public function getBlockFormats() {
		// 'block_formats' => 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6;',
		$values = array('Paragraph=p;');
		$headlines = $this->inputfield->get('headlines');
		foreach($headlines as $h) {
			$n = ltrim($h, 'h');
			$values[$h] = "Heading $n=$h;";
		}
		return implode(' ', $values);
	}
	
	/**
	 * Get style_formats
	 *
	 * @param array $defaults
	 * @return array|mixed
	 *
	 */
	public function getStyleFormats(array $defaults) {

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
	 * Add CSS that converts to style_formats and content_style
	 *
	 * Easier-to-use alternative to the importcss plugin
	 *
	 * @param string $css From the styleFormatsCSS setting
	 * @param array $settings
	 * @param array $defaults
	 *
	 */
	public function applyStyleFormatsCSS($css, array &$settings, $defaults) {

		$contentStyle = ''; // output for content_style

		// ensures each CSS rule has its own line
		$css = trim(str_replace('}', "}\n", $css));

		// converts each CSS rule to be on single line with no newlines between "key:value;" rules
		//$css = preg_replace('!\s*([{;:]|/\*|\*/)\s*!s', '\1', $css);
		$css = preg_replace('!\s*([{;:]|/\*)\s*!s', '\1', $css);

		//$css = preg_replace('!\}\s+/\*!s', '}/*', $css);

		$lines = explode("\n", $css);
		$formats = array(
			// 'Headings' => array(), 
			// 'Blocks' => array(),
			// 'Inline' => array(),
			// 'Align' => array(),
			// 'Other' => array(), // converts to root level (no parent)
		);

		while(count($lines)) {

			$line = array_shift($lines);
			$line = trim($line);
			$title = '';

			if(empty($line)) continue;
			if(strpos($line, '{') && strpos($line, '}') === false) {
				// grab next line if a rule was started but not closed
				$line .= array_shift($lines);
			}

			if(strpos($line, '{') === false) continue; // line does not start a rule

			if(strpos($line, '/*') && preg_match('!/\*(.+)?\*/!', $line, $matches)) {
				// line has comment indicating text label
				$title = trim($matches[1]);
				$line = str_replace($matches[0], '', $line);
			}

			list($selector, $styles) = explode('{', $line, 2);
			list($styles,) = explode('}', $styles, 2);

			$selector = trim($selector);

			if(strpos($selector, '#') === 0) {
				// indicates a submenu parent, i.e. #Blocks
				list($parent, $selector) = explode(' ', $selector, 2);
				$selector = trim($selector);
				$parent = ucfirst(strtolower(ltrim($parent, '#')));
			} else {
				$parent = 'Other';
			}

			if(strpos($selector, '.') !== false) {
				// element with class, i.e. span.red-text or just .red-text
				list($element, $class) = explode('.', $selector, 2);
				$class = str_replace('.', ' ', $class);
			} else {
				// element only (no class), i.e. ins or del
				$element = $selector;
				$class = '';
			}

			$stylesStr = ''; // minified styles string
			$inlineStyles = array(); // styles to also forced as inline styles on element

			foreach(explode(';', $styles) as $style) {
				// i.e. color: red
				if(strpos($style, ':') === false) continue;
				list($k, $v) = explode(':', $style);
				list($k, $v) = array(trim($k), trim($v));
				if(strtoupper($k) === $k) {
					// uppercase styles i.e. 'COLOR: red' become inline styles of element
					$k = strtolower($k);
					$inlineStyles[$k] = $v;
				}
				$stylesStr .= "$k:$v;";
			}

			if($class) {
				$_class = str_replace(' ', '.', $class);
				$contentStyle .= "$element.$_class { $stylesStr } ";
			} else {
				$contentStyle .= "$element { $stylesStr } ";
			}

			if(empty($element)) $element = '*';

			$format = array(
				'title' => ($title ? $title : $selector)
			);

			if(stripos(self::$inlines, "/$element/") !== false) {
				$format['inline'] = $element;
			} else if(strpos(self::$blocks, "/$element/") !== false) {
				$format['block'] = $element;
			} else {
				$format['selector'] = $element;
			}

			if($class) $format['classes'] = $class;
			if(count($inlineStyles)) $format['styles'] = $inlineStyles;
			if(!isset($formats[$parent])) $formats[$parent] = array();

			$formats[$parent][] = $format;
		}

		$styleFormats = array();

		foreach($formats as $parent => $format) {
			if($parent === 'Other') {
				$styleFormats[$parent] = $format;
			} else if(!isset($styleFormats[$parent])) {
				$styleFormats[$parent] = array(
					'title' => $parent,
					'items' => $format,
				);
			}
		}

		$other = isset($styleFormats['Other']) ? $styleFormats['Other'] : array();
		unset($styleFormats['Other']);

		$styleFormats = array_values($styleFormats);
		if(count($other)) $styleFormats = array_merge($styleFormats, $other);

		// add to settings
		if(isset($settings['style_formats'])) {
			$settings['style_formats'] = $this->mergeStyleFormats($settings['style_formats'], $styleFormats);
		} else if(isset($defaults['style_formats'])) {
			$settings['style_formats'] = $this->mergeStyleFormats($defaults['style_formats'], $styleFormats);
		} else {
			$settings['style_formats'] = $styleFormats;
		}

		if(isset($settings['content_style'])) {
			$settings['content_style'] .= $contentStyle;
		} else if(isset($defaults['content_style'])) {
			$settings['content_style'] = $defaults['content_style'] . $contentStyle;
		} else {
			$settings['content_style'] = $contentStyle;
		}

	}

}