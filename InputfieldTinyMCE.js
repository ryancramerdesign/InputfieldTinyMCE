/**
 * InputfieldTinyMCE.js
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 * TinyMCE 6.x, Copyright (c) 2022 Ephox Corporation DBA Tiny Technologies, Inc.
 * https://www.tiny.cloud/docs/tinymce/6/
 *
 */ 

/**
 * Handler for image uploads
 *
 * @param blobInfo
 * @param progress
 * @returns {Promise<unknown>}
 *
 */
var InputfieldTinyMCEUploadHandler = (blobInfo, progress) => new Promise((resolve, reject) => {
	
	var editor = tinymce.activeEditor;
	var $inputfield = $('#' + editor.id).closest('.Inputfield');
	var imageFieldName = $inputfield.attr('data-upload-field');
	var $imageInputfield = $('#wrap_Inputfield_' + imageFieldName);
	var pageId = $inputfield.attr('data-upload-page');
	var uploadUrl = ProcessWire.config.urls.admin + 'page/edit/?id=' + pageId + '&InputfieldFileAjax=1&ckeupload=1';
	
	const xhr = new XMLHttpRequest();
	
	xhr.withCredentials = true;
	xhr.upload.onprogress = (e) => { progress(e.loaded / e.total * 100); };
	xhr.open('POST', uploadUrl);
	
	xhr.onload = () => {
		if(xhr.status === 403) {
			reject({ message: 'HTTP Error: ' + xhr.status, remove: true });
			return;
		} else if(xhr.status < 200 || xhr.status >= 300) {
			reject('HTTP Error: ' + xhr.status);
			return;
		}
		
		var response = JSON.parse(xhr.responseText);
		
		if(!response) {
			reject('Invalid JSON in response: ' + xhr.responseText);
			return;
		}
		
		resolve(response.url);
	};
	
	xhr.onerror = () => {
		reject('Image upload failed due to a XHR Transport error. Code: ' + xhr.status);
	};
	
	$imageInputfield.trigger('pwimageupload', {
		'name': blobInfo.filename(),
		'file': blobInfo.blob(),
		'xhr': xhr
	});
});

/**
 * InputfieldTinyMCE main 
 * 
 */
var InputfieldTinyMCE = {
	
	/**
	 * Debug mode?
	 * 
	 */
	debug: false, 
	
	/**
	 * Are document events attached?
	 * 
	 */
	eventsReady: false,
	
	/**
	 * Is document ready?
	 * 
	 */
	isDocumentReady: false,
	
	/**
	 * Editor selectors to init at document ready
	 * 
 	 */	
	editorIds: [],
	
	/**
	 * Are we currently processing an editor init?
	 * 
 	 */	
	initializing: false,
	
	/**
	 * Allow lazy loaded editor init()? (adjusted by this class at runtime)
	 * 
 	 */	
	allowLazy: true, 
	
	/**
	 * Ccallback functions
	 * 
 	 */	
	callbacks: { onSetup: [], onConfig: [], onReady: [] },
	
	/**
	 * Recognized class names
	 * 
 	 */	
	cls: {
		lazy: 'InputfieldTinyMCELazy',
		inline: 'InputfieldTinyMCEInline',
		normal: 'InputfieldTinyMCENormal',
		loaded: 'InputfieldTinyMCELoaded',
		editor: 'InputfieldTinyMCEEditor'
	},
	
	/**
	 * Console log
	 * 
 	 * @param a
	 * @param b
	 * 
	 */	
	log: function(a, b) {
		if(!this.debug) return;
		if(typeof b !== 'undefined') {
			if(typeof a === 'string') a = 'TinyMCE ' + a;
			console.log(a, b);
		} else {
			console.log('TinyMCE', a);
		}
	},
	
	/**
	 * Add a setup callback function
	 * 
	 * ~~~~~
	 * InputfieldTinyMCE.onSetup(function(editor) {
	 *   // ... 
	 * }); 
	 * ~~~~~
	 * 
 	 * @param callback
	 * 
	 */	
	onSetup: function(callback) {
		this.callbacks.onSetup.push(callback); 
	},
	
	/**
	 * Add a config callback function
	 * 
	 * ~~~~~
	 * InputfieldTinyMCE.onConfig(function(settings, $editor, $inputfield) {
	 *   // ... 
	 * });
	 * ~~~~~
	 *
	 * @param callback
	 *
	 */
	onConfig: function(callback) {
		this.callbacks.onConfig.push(callback);
	},
	
	/**
	 * Add a ready callback function
	 * 
	 * ~~~~~
	 * InputfieldTinyMCE.onReady(function(editor) {
	 *   // ... 
	 * });
	 * ~~~~~
	 *
	 * @param callback
	 *
	 */
	onReady: function(callback) {
		this.callbacks.onReady.push(callback);
	},
	
	/**
	 * Set editor initializing state
	 * 
	 * @param initializing Boolean or editor ID
	 * 
	 */
	setInitializing: function(initializing) {
		this.initializing = initializing;
	},
	
	/**
	 * Is editor initializing?
	 * 
 	 * @returns {boolean} False or editor id (string)
	 * 
	 */	
	isInitializing: function() {
		return this.initializing;
	}, 
	
	/**
	 * Modify image dimensions
	 * 
	 * @param editor
	 * @param $img
	 * 
	 */
	imageResized: function(editor, img, width) {
		var t = this;
		var src = img.src;
		var hidpi = img.className.indexOf('hidpi') > -1 ? 1 : 0;
		var basename = src.substring(src.lastIndexOf('/')+1);
		var path = src.substring(0, src.lastIndexOf('/')+1);
		var dot1 = basename.indexOf('.'); 
		var dot2 = basename.lastIndexOf('.');
		var crop = '';
		
		if(basename.indexOf('-cropx') > -1 || basename.indexOf('.cropx') > -1) {
			// example: gonzo_the_great.205x183-cropx38y2-is.jpg
			// use image file as-is
			// @todo re-crop and resize from original?
		} else if(dot1 !== dot2) {
			// extract any existing resize data present to get original file
			// i.e. file.123x456-is-hidpi.jpg => file.jpg
			var basename2 = basename.substring(0, dot1) + basename.substring(dot2);
			src = path + basename2;
		}
		
		var url = ProcessWire.config.urls.admin + 'page/image/resize' + 
			'?json=1' + 
			'&width=' + width + 
			'&hidpi=' + hidpi + 
			'&file=' + src; 
		
		t.log('Resizing image to width=' + width, url); 
		
		jQuery.getJSON(url, function(data) {
			editor.dom.setAttrib(img, 'src', data.src); 
			// editor.dom.setAttrib(img, 'width', data.width);
			// editor.dom.setAttrib(img, 'height', data.height);
			t.log('Resized image to width=' + data.width, data.src);
		});
	},
	
	/**
	 * Called when an element has an align class applied to it
	 * 
	 * This function ensures only 1 align class is applied at a time.
	 * 
 	 * @param editor
	 * 
	 */	
	elementAligned: function(editor) {
		var selection = editor.selection;
		var node = selection.getNode();
		var className = node.className;
		
		// if only one align class then return now		
		if(className.indexOf('align') === className.lastIndexOf('align')) return;
		
		var alignNames = [];
		var classNames = className.split(' ');
		
		for(var n = 0; n < classNames.length; n++) {
			if(classNames[n].indexOf('align') === 0) {
				alignNames.push(classNames[n]);
			}
		}
	
		// pop off last align class, which we will keep
		alignNames.pop(); 
		
		for(var n = 0; n < alignNames.length; n++) {
			className = className.replace(alignNames[n], '');
		}
		
		node.className = className.trim();
	},
	
	/**
	 * Init callback function
	 *
	 * @param editor
	 * @param features
	 * 
	 */	
	editorReady: function(editor, features) {
		
		var t = this;
		var $editor = $('#' + editor.id);
		var $inputfield = $editor.closest('.Inputfield');
		var inputTimeout = null;
		
		editor.on('Dirty', function() {
			$inputfield.trigger('change');
			// t.log('event Dirty');
		});
	
		editor.on('input', function() {
			if(inputTimeout) clearTimeout(inputTimeout);
			inputTimeout = setTimeout(function() {
				$inputfield.trigger('change');
				// t.log('event Input');
			}, 500); 
		});
		
		// for image resizes
		if(features.indexOf('imgResize') > -1) {
			editor.on('ObjectResized', function(e, data) {
				// @todo account for case where image in figure is resized, and figure needs its width updated with the image
				if(e.target.nodeName === 'IMG') {
					t.imageResized(editor, e.target, e.width);
				}
			});
		}
		
		for(var n = 0; n < t.callbacks.onReady.length; n++) {
			t.callbacks.onReady[n](editor);
		}
		
		editor.on('ExecCommand', function(e, f) {
			if(e.command === 'mceFocus') return;
			t.log('command: ' + e.command, e);
			if(e.command === 'mceToggleFormat' && e.value && e.value.indexOf('align') === 0) {
				var editor = this;
				t.elementAligned(editor);
			}
		});
		
		/*
		 * uncomment to show inline init effect
		if(jQuery.ui) {
			if($editor.hasClass('InputfieldTinyMCEInline')) {
				$editor.effect('highlight', {}, 500);
			}
		}
		*/

		/*		
		editor.on('ResizeEditor', function(e) {
			// editor resized
			t.log('ResizeEditor');
		}); 
		*/
	},
	
	/**
	 * config.setup handler function
	 * 
 	 * @param editor
	 * 
	 */	
	setupEditor: function(editor) {
		var t = InputfieldTinyMCE;
		var $editor = jQuery('#' + editor.id);
		
		if($editor.hasClass(t.cls.loaded)) {
			t.log('mceInit called on input that is already loaded', editor.id);
		} else {
			$editor.addClass(t.cls.loaded);
		}
		
		for(var n = 0; n < t.callbacks.onSetup.length; n++) {
			t.callbacks.onSetup[n](editor); 
		}
	
		/*
		editor.on('init', function() {
			// var n = performance.now();
			// t.log(editor.id + ': ' +  (n - mceTimer) + ' ms');
		}); 
		*/
	
		/*	
		editor.on('Load', function() {
			// t.log('iframe loaded', editor.id);
		}); 
		*/
	},
	
	/**
	 * Destroy given editors
	 *
	 * @param $editors
	 *
	 */
	destroyEditors: function($editors) {
		var t = this;
		$editors.each(function() {
			var $editor = $(this);
			if(!$editor.hasClass(t.cls.loaded)) return;
			var editorId = $editor.attr('id');
			var editor = tinymce.get(editorId);
			$editor.removeClass(t.cls.loaded).removeClass(t.cls.lazy);
			t.log('destroyEditor', editor.id);
			// $editor.css('display', 'none');
			editor.destroy();
		});
	},
	
	/**
	 * Reset given editors (destroy and re-init)
	 *
	 * @param $editors
	 *
	 */
	resetEditors: function($editors) {
		var t = this;
		$editors.each(function() {
			var $editor = $(this);
			if(!$editor.hasClass(t.cls.loaded)) return;
			var editorId = $editor.attr('id');
			var editor = tinymce.get(editorId);
			editor.destroy();
			$editor.removeClass(t.cls.loaded);
			// t.init('#' + editorId, 'resetEditors');
		});
		t.initEditors($editors);
	},
	
	/**
	 * Initialize given jQuery object editors
	 *
	 * @param $editors
	 *
	 */
	initEditors: function($editors) {
		var t = this;
		$editors.each(function() {
			var $editor = $(this);
			var editorId = $editor.attr('id');
			if($editor.hasClass(t.cls.loaded)) return;
			//t.log('init', id);
			t.init('#' + editorId, 'initEditors');
		});
	},
	
	/**
	 * Get config (config + custom settings)
	 * 
 	 * @param $editor Editor Textarea (Regular) or div (Inline)
	 * @param $inputfield Editor Wrapping .Inputfield element
	 * @returns {{}}
	 * 
	 */	
	getConfig: function($editor, $inputfield) {

		var configName = $inputfield.attr('data-configName');
		var settings = ProcessWire.config.InputfieldTinyMCE.settings.default;
		var namedSettings = ProcessWire.config.InputfieldTinyMCE.settings[configName];
		var dataSettings = $inputfield.attr('data-settings');

		if(typeof settings === 'undefined') {
			settings = {};
		} else {
			settings = jQuery.extend(true, {}, settings);
		}
		
		if(typeof namedSettings === 'undefined') {
			this.log('Can’t find ProcessWire.config.InputfieldTinyMCE.settings.' + configName);
		} else {
			jQuery.extend(settings, namedSettings);
		}
		
		if(typeof dataSettings === 'undefined') {
			dataSettings = null;
		} else if(dataSettings && dataSettings.length > 2) {
			dataSettings = JSON.parse(dataSettings);
			jQuery.extend(settings, dataSettings);
		}
		
		if(settings.inline) settings.content_css = null; // we load this separately for inline mode
		
		for(var n = 0; n < this.callbacks.onConfig.length; n++) {
			this.callbacks.onConfig[n](settings, $editor, $inputfield);
		}
		
		return settings;
	},
	
	/**
	 * Document ready events
	 * 
	 */
	initDocumentEvents: function() {
		var t = this;

		jQuery(document)
			.on('click mouseover focus touchstart', '.' + t.cls.inline + ':not(.' + t.cls.loaded + ')', function(e) {
				// we initialize the inline editor only when moused over
				// so that a page can handle lots of editors at once without
				// them all being active
				if(InputfieldTinyMCE.isInitializing() !== false) return;
				t.init('#' + this.id, 'event.' + e.type);
			})
			.on('image-edit sort-stop', '.InputfieldTinyMCE', function(e) {
				// all "normal" editors that are also "loaded"
				var $editors = $(this).find('.' + t.cls.normal + '.' + t.cls.loaded);
				if($editors.length) {
					t.log(e.type + '.resetEditors', $editors);
					// force all to load
					t.allowLazy = false;
					t.resetEditors($editors);
					t.allowLazy = true;
				}
			})
			.on('reload', '.Inputfield', function() {
				var $inputfield = $(this);
				var $editors = $inputfield.find('.' + t.cls.loaded);
				if($editors.length) {
					t.log('reload', $inputfield.attr('id'));
					t.destroyEditors($editors);
				}
			})
			.on('reloaded', '.Inputfield', function() {
				var $inputfield = $(this);
				var s = '.' + t.cls.editor + ':not(.' + t.cls.loaded + '):not(.' + t.cls.lazy + ')';
				var $editors = $inputfield.find(s);
				if($editors.length) {
					t.log('reloaded', $inputfield.attr('id'));
					t.initEditors($editors);
				}
				return false;
			})
			/*
			.on('sortstart', function() {
				var $editors = $(e.target).find('.InputfieldTinyMCELoaded'); 
				$editors.each(function() {
					var $editor = $(this);
					t.log('sortstart', $editor.attr('id'));
				}); 
			})
			*/
			.on('sortstop', function(e) {
				var $editors = $(e.target).find('.' + t.cls.loaded);
				if($editors.length) {
					t.log('sortstop');
					t.resetEditors($editors);
				}
			})
			.on('clicklangtab wiretabclick', function(e, $newTab) {
				var $editors = $newTab.find('.' + t.cls.lazy + ':visible');
				t.log(e.type, $newTab.attr('id'));
				if($editors.length) t.initEditors($editors);
			});
			
		this.eventsReady = true;
	},
	
	/**
	 * Document ready
	 * 
 	 */	
	documentReady: function() {
		this.debug = ProcessWire.config.InputfieldTinyMCE.debug;
		this.isDocumentReady = true;
		this.log('documentReady', this.editorIds);
		while(this.editorIds.length > 0) {
			var editorId = this.editorIds.shift();
			this.init(editorId, 'documentReady');
		}
		this.initDocumentEvents();
		if(this.debug) {
			this.log('qty', 
				'normal=' + $('.' + this.cls.normal).length + ', ' + 
				'inline=' +  $('.' + this.cls.inline).length + ', ' + 
				'lazy=' + $('.' + this.cls.lazy).length + ', ' + 
				'loaded=' + $('.' + this.cls.loaded).length 
			);
		}
	},

	/**
	 * Initialize an editor
	 * 
	 * ~~~~~
	 * InputfieldTinyMCE.init('#my-textarea');
	 * ~~~~~
	 *
	 * @param id Editor id or selector string
	 * @param caller Optional name of caller (for debugging purposes)
	 * @returns {boolean}
	 *
	 */
	init: function(id, caller) {
		
		var $editor, config, features, $inputfield, selector, _id = id, t = this;
		
		if(!this.isDocumentReady) {
			this.editorIds.push(id); 
			return true;
		}
	
		this.setInitializing(id);
		
		caller = (t.debug && typeof caller !== 'undefined' ? ' (caller=' + caller + ')' : '');
		
		if(typeof id === 'string') {
			// literal id or selector string
			if(id.indexOf('#') === 0 || id.indexOf('.') === 0) {
				selector = id;
				id = '';
			} else {
				selector = '#' + id;
			}
			$editor = jQuery(selector);
			if(id === '') id = $editor.attr('id');
			
		} else if(typeof id === 'object') {
			// element or jQuery element
			if(id instanceof jQuery) {
				$editor = id;
			} else {
				$editor = $(id);
			}
			id = $editor.attr('id');
			selector = '#' + id;
		}
		
		if(!$editor.length) {
			console.error('Cannot find element to init TinyMCE: ' + _id); 
			this.setInitializing(false);
			return false;
		}
		
		var isLazy = $editor.hasClass(t.cls.lazy);
		
		if(t.allowLazy && !isLazy && !$editor.is(':visible') && !$editor.hasClass(t.cls.inline)) {
			$editor.addClass(t.cls.lazy);
			this.log('init-lazy', id + caller);
			return true;
		} else if(isLazy) {
			$editor.removeClass(t.cls.lazy);
		}
		
		this.log('init', id + caller);
		
		if(id.indexOf('Inputfield_') === 0) {
			$inputfield = jQuery('#wrap_' + id);
		} else {
			$inputfield = jQuery('#wrap_Inputfield_' + id);
		}
		
		if(!$inputfield.length) {
			$inputfield = $editor.closest('.Inputfield');
		}
		
		features = $inputfield.attr('data-features');
		
		config = this.getConfig($editor, $inputfield);
		config.selector = selector;
		config.setup = this.setupEditor;
		config.init_instance_callback = function(editor) {
			t.setInitializing('');
			setTimeout(function() { if(t.isInitializing() === '') t.setInitializing(false); }, 100);
			t.log('ready', editor.id); 
			t.editorReady(editor, features);
		}
		
		if(features.indexOf('imgUpload') > -1) {
			config.images_upload_handler = InputfieldTinyMCEUploadHandler;
		}
		
		tinymce.init(config);
		
		return true;
	}
};

jQuery(document).ready(function() {
	InputfieldTinyMCE.documentReady();
}); 

/*
InputfieldTinyMCE.onSetup(function(editor) {
	editor.ui.registry.addButton('hello', {
		icon: 'user',
		text: 'Hello',
		onAction: function() {
			editor.insertContent('Hello World!')
		}
	});
}); 
*/
