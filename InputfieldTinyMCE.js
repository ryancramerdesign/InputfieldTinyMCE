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
	 * Ccallback functions
	 * 
 	 */	
	callbacks: { onSetup: [], onConfig: [], onReady: [] }, 
	
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
	 * Init callback function
	 * 
 	 * @param editor
	 * @param features
	 * 
	 */	
	editorReady: function(editor, features) {
		
		var t = this;
		var $editor = $('#' + editor.id);
		
		editor.on('Dirty', function() {
			$editor.trigger('change');
		});
		
		editor.on('input', function() {
			$editor.trigger('change');
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
		
		/*
		if($editor.hasClass('InputfieldTinyMCEInlineFocus')) {
			console.log("mceFocus");
			setTimeout(function() {
				editor.show();
				editor.execCommand('mceFocus');
			}, 1000); 
		}
		 */

		editor.on('ExecCommand', function(e, f) {
			if(e.command === 'mceFocus') return;
			t.log('command: ' + e.command, e);
			if(e.command === 'mceToggleFormat' && e.value && e.value.indexOf('align') === 0) {
				var editor = this;
				var selection = editor.selection;
				var node = selection.getNode();
				t.log('e.value', e.value);
				t.log('node', node); 
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
		
		// editor.on('Change', function(e) { $input.trigger('change'); });
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
		
		if($editor.hasClass('InputfieldTinyMCELoaded')) {
			t.log('mceInit called on input that is already loaded', editor.id);
		} else {
			$editor.addClass('InputfieldTinyMCELoaded');
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
			if(!$editor.hasClass('InputfieldTinyMCELoaded')) return;
			var editorId = $editor.attr('id');
			var editor = tinymce.get(editorId);
			$editor.removeClass('InputfieldTinyMCELoaded');
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
			if(!$editor.hasClass('InputfieldTinyMCELoaded')) return;
			$editor.removeClass('InputfieldTinyMCELoaded');
			var editorId = $editor.attr('id');
			var editor = tinymce.get(editorId);
			editor.destroy();
			t.init('#' + editorId);
		});
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
			var id = $editor.attr('id');
			if($editor.hasClass('InputfieldTinyMCELoaded')) return;
			t.log('init', id);
			t.init('#' + id);
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
			.on('click mouseover focus touchstart', '.InputfieldTinyMCEInline:not(.InputfieldTinyMCELoaded)', function(e) {
				// we initialize the inline editor only when moused over
				// so that a page can handle lots of editors at once without
				// them all being active
				if(InputfieldTinyMCE.isInitializing() !== false) return;
				t.init('#' + this.id);
			})
			.on('image-edit sort-stop', '.InputfieldTinyMCE', function(e) {
				var $editors = $(this).find('.InputfieldTinyMCENormal.InputfieldTinyMCELoaded');
				if($editors.length) {
					t.log('image-edit', e);
					t.resetEditors($editors);
				}
			})
			.on('reload', '.Inputfield', function() {
				var $inputfield = $(this);
				var $editors = $inputfield.find('.InputfieldTinyMCELoaded');
				if($editors.length) {
					t.log('reload', $inputfield.attr('id'));
					t.destroyEditors($editors);
				}
			})
			.on('reloaded', '.Inputfield', function() {
				var $inputfield = $(this);
				var $editors = $inputfield.find('.InputfieldTinyMCEEditor:not(.InputfieldTinyMCELoaded)');
				if($editors.length) {
					t.log('reloaded', $inputfield.attr('id'));
					t.resetEditors($editors);
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
				var $editors = $(e.target).find('.InputfieldTinyMCELoaded');
				if($editors.length) {
					t.log('sortstop');
					t.resetEditors($editors);
				}
			});
		this.eventsReady = true;
	},
	
	/**
	 * Document ready
	 * 
 	 */	
	documentReady: function() {
		this.isDocumentReady = true;
		this.log('documentReady', this.editorIds);
		while(this.editorIds.length > 0) {
			var editorId = this.editorIds.shift();
			this.init(editorId);
		}
		this.initDocumentEvents();
	},

	/**
	 * Initialize an editor
	 * 
	 * ~~~~~
	 * InputfieldTinyMCE.init('#my-textarea');
	 * ~~~~~
	 *
	 * @param id Editor id or selector string
	 * @returns {boolean}
	 *
	 */
	init: function(id) {
		
		var $editor, config, features, $inputfield, selector, _id = id;
		var t = this;
		
		if(!this.isDocumentReady) {
			this.editorIds.push(id); 
			return true;
		}
	
		this.setInitializing(id);
		this.log('init', id);
		
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
