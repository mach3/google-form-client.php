<?php

/*!
 * Google_Form_Client
 * ------------------
 * @class Client library for Google Form
 * @version 0.1.0
 * @lisence MIT
 * @author mach3 <http://github.com/mach3>
 */

class Google_Form_Client {

	private $options = array(
		"cache" => false,
		"cache_dir" => "cache",
		"cache_lifetime" => 3600,
		"cache_filemode" => 0660,
		"curl" => array(
			CURLOPT_TIMEOUT => 30
		)
	);

	private $url = null;
	private $fields = null;
	private $map = null;
	private $errors = array();

	public function __construct($url = null, $options = null){
		if($options){
			$this->config($options);
		}
		if($url){
			$this->load($url);
		}
	}

	/**
	 * Configure options
	 * @param {String|Array} key (optional)
	 * @param {*} value (optional)
	 * @return {*}
	 */
	public function config(/* $key, $value */){
		$args = func_get_args();
		switch(count($args)){
			case 0:
				return $this->options;
			case 1:
				if(gettype($args[0]) === "string"){
					return $this->get($args[0], $this->options);
				}
				if(gettype($args[0]) === "array"){
					foreach($args[0] as $key => $value){
						$this->config($key, $value);
					}
				}
				break;
			case 2:
				$old = $this->options[$args[0]];
				$this->options[$args[0]] = (is_array($old) && is_array($args[1])) ? array_merge($old, $args[1]) : $args[1];
				break;
			default: break;
		}
		return $this;
	}

	/**
	 * Load Google Form by url and initialize fields
	 * @param {String} url
	 */
	public function load($url){
		$cache = $this->config("cache");
		// Fetch
		$html = $cache ? $this->cache($url) : $this->curl($url);
		$doc = new DOMDocument();
		$doc->loadHTML($html);

		// Get dest url
		$this->url = $this->query_selector("form", $doc)[0]->getAttribute("action");

		// Generate field data
		$this->fields = array();
		foreach($this->query_selector(".ss-form-question", $doc) as $i => $item){
			// name, label
			$name = explode("\n", $this->query_selector("label.ss-q-item-label", $item)[0]->nodeValue)[0];
			$label = $this->query_selector(".ss-secondary-text", $item)[0]->nodeValue;
			$label = $label ? $label : $name;

			// input and its attributes
			$inputs = $this->query_selector("input, textarea, select", $item);
			$id = $inputs[0]->getAttribute("name");
			$type = (in_array($inputs[0]->tagName . "", array("textarea", "select"))) ? $inputs[0]->tagName 
				: $inputs[0]->getAttribute("type");

			// values
			$values = array();
			switch($type){
				case "select":
					foreach($this->query_selector("option", $inputs[0]) as $option){
						$value = $option->getAttribute("value");
						if(! empty($value)){
							array_push($values, $value);
						}
					}
					break;
				case "checkbox":
				case "radio":
					foreach($inputs as $input){
						array_push($values, $input->getAttribute("value"));
					}
					break;
				default: $values = null; break;
			}

			$field = (object) array(
				"id" => $id,
				"name" => $name,
				"label" => $label,
				"required" => $inputs[0]->hasAttribute("required"),
				"type" => $type,
				"values" => $values,
				"pattern" => $inputs[0]->getAttribute("pattern")
			);

			$field->input = $this->genInput($field);

			if(! $field->label){
				$field->label = $field->name;
			}

			$this->fields[$id] = $field;
		}

		// Generate map
		$this->map = array();
		foreach($this->fields as $key => $field){
			$this->map[$field->name] = $key;
		}

		return $this;
	}

	/**
	 * Post Google Form with vars
	 * @param {Array} $vars
	 */
	public function post($vars){
		// Cheap Validate
		if(! $this->fields || true !== $this->validate($vars)){
			return false;
		}
		// Post & check response
		$postvars = $this->genParams($vars);
		$res = $this->curl($this->url, true, $postvars);
		if($res){
			$doc = new DOMDocument();
			$doc->loadHTML($res);
			if(! count($this->query_selector("#ss-form", $doc))){
				return true;
			}
		}
		return false;
	}

	/**
	 * Cheaply validate values
	 * @param {Array} vars
	 * @return {Array|Boolean} ... if valid return TRUE, otherwise return errors array
	 */
	public function validate($vars){
		$e = array();
		$vars = $this->genParams($vars);

		foreach($this->fields as $key => $field){
			$valid = true;
			$value = array_key_exists($key, $vars) ? $vars[$key] : null;

			// required
			if($field->required && empty($value)){
				array_push($e, "{$field->name}: Empty");
				continue;
			}

			// types
			switch($field->type){
				case "number":
					$valid = is_numeric($value);
					break;
				case "email":
					$valid = filter_var($value, FILTER_VALIDATE_EMAIL);
					break;
				case "url":
					$valid = filter_var($value, FILTER_VALIDATE_URL);
					break;
				case "radio":
				case "select":
					$valid = in_array($value, $field->values);
					break;
				case "checkbox":
					if("array" !== gettype($value)){
						$valid = false;
						break;
					}
					foreach($value as $v){
						if(! in_array($v, $field->values)){
							$valid = false; break;
						}
					}
					break;
				case "text":
					if($field->pattern){
						$valid = !! preg_match("/{$field->pattern}/", $value);
					}
					break;
				default: break;
			}
			if(! $valid){
				array_push($e, "{$field->name}: Invalid value");
			}
		}
		if(count($e)){
			$this->errors = array_merge($this->errors, $e);
			return false;
		}
		return true;
	}

	/**
	 * Get error messages
	 * @return {Array}
	 */
	public function getErrors(){
		return $this->errors;
	}

	/**
	 * Render form HTML with template and field object
	 * @param {String} __template__ ... template file path
	 * @param {Array} __vars__ (optional)
	 */
	public function render($__template__, $__vars__ = array()){
		$__vars__ = array_merge($__vars__, array(
			"fields" => $this->fields,
			"instance" => $this
		));
		if(file_exists($__template__)){
			ob_start();
			extract($__vars__);
			require($__template__);
			$content = ob_get_clean();
			return $content;
		}
		return null;
	}

	/**
	 * Generate input|textarea input
	 * @param {Object} field
	 * @return {String} html
	 */
	private function genInput($field){
		$dom = new DOMDocument();
		if(in_array($field->type, array("radio", "checkbox"))){
			foreach($field->values as $value){
				$label = $dom->createElement("label");
				$input = $dom->createElement("input");
				$input->setAttribute("type", $field->type);
				$input->setAttribute("name", $field->name);
				if($field->required){
					$input->setAttribute("required", "required");
				}
				$label->appendChild($input);
				$label->appendChild($dom->createTextNode($value));
				$dom->appendChild($label);
			}
		} else if($field->type === "select") {
			$select = $dom->createElement("select");
			$select->setAttribute("name", $field->name);
			if($field->required){
				$select->setAttribute("required", "required");
			}
			foreach($field->values as $value){
				$option = $dom->createElement("option");
				$option->setAttribute("value", $value);
				$option->appendChild($dom->createTextNode($value));

				$select->appendChild($option);
			}
			$dom->appendChild($select);
		} else {
			$node = $dom->createElement($field->type === "textarea" ? "textarea" : "input");
			if($field->type !== "textarea"){
				$node->setAttribute("type", $field->type);
			}
			$node->setAttribute("name", $field->name);
			if($field->required){
				$node->setAttribute("required", "required");
			}
			if($field->pattern){
				$node->setAttribute("pattern", $field->pattern);
			}
			$dom->appendChild($node);
		}

		return $dom->saveHTML();
	}

	/**
	 * Generate parameters by map (name => id)
	 * @param {Array} vars
	 */
	private function genParams($vars){
		$params = array();
		foreach($vars as $key => $value){
			if(! array_key_exists($key, $this->map)){ continue; }
			$params[$this->map[$key]] = $value;
		}
		return (array) $params;
	}

	/**
	 * Simple queryselector
	 * @param {String} selector
	 * @param {DOMElement} el
	 * @return {Array} ... Collection of DOMElement
	 */
	private function query_selector($sel, $el){
		$nodes = array();

		// multi selectors
		if(strpos($sel, ",") !== false){
			$sel = explode(",", $sel);
			foreach($sel as $s){
				$nodes = array_merge($nodes, $this->query_selector(trim($s), $el));
			}
			return $nodes;
		}

		// parse descendants
		$path = array_filter(explode(" ", $sel), function($value){
			return ! empty($value);
		});
		if(count($path) > 1){
			$node = $el;
			foreach($path as $i => $p){
				$node = $this->query_selector($p, $node);
			}
			return $node;
		}

		// el may be array
		$el = gettype($el) === "array" ? $el : array($el);

		// parse selector string
		$tag = preg_match("/(^[a-zA-Z]+)?/", $sel, $m) ? $m[0] : "*";
		$tag = $tag ? $tag : "*";
		$id = preg_match("/#([\w\-]+)/", $sel, $m) ? $m[1] : null;
		$classes = preg_match_all("/\.([\w\-]+)/", $sel, $m) ? $m[1] : array();
		$attrs = preg_match_all("/(?:[\[\s])(?:([\w\-]+)=([^\]\s]+))/", $sel, $m) ? array_combine($m[1], $m[2]) : array();

		// filter
		foreach($el as $e){
			foreach($e->getElementsByTagName($tag) as $i => $node){
				$valid = true;
				if($id && $id !== $node->getAttribute("id")){ continue; }
				foreach($classes as $class){
					if(false === strpos($node->getAttribute("class"), $class)){
						$valid = false;
						break;
					}
				}
				foreach($attrs as $key => $value){
					if($node->getAttribute($key) !== $value){
						$valid = false;
						break;
					}
				}
				if($valid){
					array_push($nodes, $node);
				}
			}
		}

		return $nodes;
	}

	/**
	 * Get value from array|object 
	 * @param {String} key
	 * @param {Array|Object} obj
	 * @param {*} default
	 */
	private function get($key, $obj, $default = null){
		$obj = (array) $obj;
		return array_key_exists($key, $obj) ? $obj[$key] : $default;
	}

	/**
	 * Get content from cache, or save content as cache
	 * @param {String} url
	 * @param {String} content (optional)
	 */
	private function cache($url, $content = null){
		$path = $this->config("cache_dir") . "/" . urlencode($url);
		if($content){
			file_put_contents($path, $content);
			chmod($path, $this->config("cache_filemode"));
			return $content;
		}
		if(file_exists($path) && (time() - filemtime($path)) < $this->config("cache_lifetime")){
			return file_get_contents($path);
		}
		return $this->cache($url, $this->curl($url));
	}

	/**
	 * Fetch remote content by url
	 * @param {String} url
	 * @param {Boolean} post (optional)
	 * @param {Array} postvars (optional)
	 */
	private function curl($url, $post = false, $postvars = null){
		$ch = curl_init();
		foreach($this->config("curl") as $key => $value){
			curl_setopt($ch, $key, $value);
		}
		if($post){
			curl_setopt($ch, CURLOPT_POST, true);
			if($postvars){
				$postfields = preg_replace("/%5B[0-9]+%5D/", "", http_build_query($postvars));
				curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
			}
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}

}
