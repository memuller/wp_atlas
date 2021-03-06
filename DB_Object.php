<?php 
	
	class DB_Object {

		static $table_sufix ;
		static $table_name ; 
		static $fields ;
		static $pk_type ;
		static $belongs_to ;
		static $has_many ; 
		static $has_one ;

		public $new_record ; 
		private $creation_parameters ;
		
		static function get_class_name(){
			return get_called_class() ;
		}

		static function table_name(){
			global $wpdb ;
			return $wpdb->prefix . static::$table_sufix ;
		}

		static function field_list($prefix=""){
			$list = array() ;
			foreach (static::$fields as $field_name => $field_options) {
				$list[]= ! empty($prefix) ? $prefix.'.'.$field_name : $field_name ;
			}
			return implode(', ', $list) ;
		}

		static function unique_field(){

			if(isset(static::$compound_indexes)){
				return static::$compound_indexes['unique'] ;
			}

			$field = "id";
			
			foreach (static::$fields as $field_name => $field_options) {
				if(isset($field_options['unique'])){
					$field = $field_name ; break ;
				}
			}
			return $field ;
		}

		static function belongs_to_class_name(){
			$splat = explode("\\", static::get_class_name()) ; $namespace = $splat[0] ;
			return $namespace . "\\" .ucfirst(static::$belongs_to) ;
		}
		static function build(){

		}
		static function unique_field_where_clausule(){
			
			$field = static::unique_field();
			if(is_array($field)){
				$clausule = array(); ;
				foreach ($field as $obj) {
					$clausule[]= "$obj = %d" ; 
				}
				return implode(" AND ", $clausule) ;
			}
			
			return  $field == 'id' ? $clausule = "id = %d" : $clausule = "$field = %s" ; 
		}

		static function build_database(){
			global $wpdb ;
			
			require_once ABSPATH . 'wp-admin/includes/upgrade.php' ;

			$field_definitions = array() ;
			if(!isset(static::$pk_type)) static::$pk_type = 'bigint(20) unsigned auto_increment ' ;
			$field_definitions[]= "id ". static::$pk_type ." not null " ;
			$key_definitions = array() ;
			$key_definitions[] = "primary key id (id)" ;

			if (isset(static::$belongs_to)) {
				if(!is_array(static::$belongs_to)) static::$belongs_to = array(static::$belongs_to);
				foreach (static::$belongs_to as $obj) {
					$field_definitions[]= $obj."_id bigint(20) unsigned not null" ;
					$key_definitions[]= "index ".$obj."_id (".$obj."_id)";
				}
				
			}

			foreach (static::$fields as $field_name => $field_options) {
				if( !isset($field_options['size'])) $field_options['size'] = 255 ;
				
				if( !isset($field_options['type'])){
					$field_type = "varchar(".$field_options['size'].")" ; $is_text = true ;
				} else {
					
					switch ($field_options['type']) {
						
						case 'enum':
						case 'set':
							$values_list = array() ; $is_text = true ;
							foreach ($field_options['values'] as $value) {
								$values_list[]= "'". $value . "'" ;
							}
							$field_type = sprintf("%s(%s)", $field_options['type'], implode(',', $values_list)) ; 
							break ;
						case 'datetime':
						case 'date':
							$field_type = 'datetime' ; $is_text = true ;
							break ;
						
						default:
							$field_type = $field_options['type'] ;
							break ;
					}	
				}

				if(isset($field_options['default'])){
					if(isset($is_text) && $is_text) $field_options['default'] = "'".$field_options['default']."'" ;
					$default = "default ".$field_options['default'] ;
				} else {
					$default = "" ;
				}

				$required = $field_options['required'] ? "not null" : "null" ;
				$field_definitions[]= "$field_name $field_type $default $required" ;

				if($field_options['unique']){
					$key_definitions[]= "unique key $field_name ($field_name)" ; 
				}
				if($field_options['index']){
					$key_definitions[]= "index $field_name ($field_name)" ; 
				}
			}
			if(isset(static::$compound_indexes)){
				foreach (static::$compound_indexes as $index => $fields) {
					$key_definitions[]= sprintf("$index key (%s)", implode(',', $fields)) ;
				}
			}
			$sql = sprintf("CREATE TABLE %s (
				%s ,
				%s
			);", static::table_name(), implode(","."\n", $field_definitions), implode(","."\n", $key_definitions)) ;
			dbDelta($sql) ;
			 		
		}

		function __construct($values, $save = true){
			global $wpdb ;
			if(is_numeric($values)){
				$save = false ; 
				$sql = $wpdb->prepare("select * from ".static::table_name()." where id = %d", $values) ;
				$values = $wpdb->get_row($sql, ARRAY_A) ;
			}
			$this->creation_parameters = $values ;
			if($save === true){
				$this->persist();
			} else {
				if(isset(static::$belongs_to)) $parent_fields = array();
				foreach ($this->creation_parameters as $k => $v) {
					if(isset(static::$belongs_to) && static::is_parent_field($k)){ 
						$parent_fields[$k]= $v ;
					} else {
						$this->$k = $v ;
					}
				}
				if(isset(static::$belongs_to)){
					$fk_name = static::$belongs_to . '_id' ;
					$property_name = static::$belongs_to ; $parent_class = static::belongs_to_class_name() ;
					$this->$property_name = !empty($parent_fields) ? new $parent_class($parent_fields) : new $parent_class($this->$fk_name);
				}
				$this->new_record = false ; 
			}
		}

		static function is_parent_field($field){
			$parent_class = static::belongs_to_class_name() ;
			return in_array($field, array_keys($parent_class::$fields)) ;
		}

		static public function find_or_create($args){
			global $wpdb ;
			$clausule = static::unique_field_where_clausule() ;

			if(isset(static::$belongs_to)) {
					$param = array(); $key_class = static::belongs_to_class_name() ; $key_creation_args = array();
					foreach (static::unique_field() as $field_name) {
						$param[$field_name]= $args[$field_name] ;
					}
					
					foreach($args as $field_name => $field_value){
						if(strstr($field_name, static::$belongs_to)){
							$inherited_field_name = $field_name ; $field_name = str_replace(static::$belongs_to."_", "", $field_name) ;
							$key_creation_args[$field_name]= $field_value ;
						}
					}
					$belongs_to_fk_name = static::$belongs_to . "_id" ;
					$key = $key_class::find_or_create($key_creation_args) ;
					$param[$belongs_to_fk_name] = $key->id ; 

				
				$sql = vsprintf("select * from ".static::table_name()." where $clausule", $param) ;
			} else {
				if( is_array($args)){
					$param = $args[static::unique_field()] ;
				} else {
					$param = $args ; 
				}
				$sql = $wpdb->prepare(
					"select * from ".static::table_name()." where $clausule", $param) ;
			}	
				$obj = $wpdb->get_row($sql, ARRAY_A) ;
				
			if($obj){
				return new static($obj, false) ;
			} else {
				return new static($args, true) ;
			}
		}

		public function persist(){
			global $wpdb ;
			$fields = array();

			if(static::$belongs_to){
				if(!is_array(static::$belongs_to)) static::$belongs_to = array();
				foreach (static::$belongs_to as $foreigner) {
					$key_class_fields = array() ; $local_fields_from_key = array();
					$splat = explode("\\", static::get_class_name()) ; $namespace = $splat[0] ;
			 		$key_class = $namespace . "\\" .ucfirst($foreigner) ;
					$fk_field = $foreigner."_id" ;
					$property_name = $foreigner ;
					
					if(!isset($this->$fk_field)){

						foreach ( $key_class::$fields as $field_name => $field_options) {
							$compound_field_name = $foreiger."_$field_name" ;
							$value = isset($this->$compound_field_name) ? $this->$compound_field_name : $this->creation_parameters[$compound_field_name] ;
							$key_class_fields[$field_name] = $value ;

						}
						$key_obj = $key_class::find_or_create($key_class_fields) ;
						if($key_obj){
							$fields[$fk_field] = $key_obj->id ; 
							$this->$property_name = $key_obj ;
						}
					}
				}

			}
			foreach (static::$fields as $field_name => $field_options) {
				
				if(isset($field_options['mechanized'])){
					if(!isset($field_options['mechanize_once']) || ! isset($this->id)){
						if($field_options['type'] == 'timestamp'){
							$this->creation_parameters[$field_name] = date('Y-m-d H:i:s');
						} else {
							$this->creation_parameters[$field_name] =  call_user_func($field_options['mechanized'][0], $field_options['mechanized'][1]);
						}
					}
				}
				$value = isset($this->$field_name) ? $this->$field_name : $this->creation_parameters[$field_name] ; 
				$value = !isset($value) ? $field_options['default'] : $value ; 

				if(empty($value) && $field_options['required'] && intval($value) != 0){
					trigger_error("$field_name is required.", E_USER_ERROR) ;
				}

				if($field_options['type'] == 'date'){
					$value = explode('/', $value) ;
					$value = sprintf("%s-%s-%s", $value[2], $value[1], $value[0]);
				}

				$fields[$field_name] =  $value; 
				$this->$field_name = $value ;
			}

			if(! isset($this->id)){
				$this->new_record = true ; 
				$result = $wpdb->insert(static::table_name(), $fields) ;
				$this->id = $wpdb->insert_id ; 
			} else {
				$this->new_record = false ;
				$wpdb->update(static::table_name(), $fields, array('id' => $this->id)) ;
			}
		}

		static function is_text_field($field){
			if(! $type = static::$fields[$field]['type']) return true ; 
			if(in_array($type, array('text', 'enum', 'set', 'datetime'))) return true ;
			if(stripos($type, 'int')) return false ;
			

		}

		static function find($id){
			global $wpdb;
			$sql = $wpdb->prepare("select * from ".static::table_name()." where id=%s", $id);
			if($object = $wpdb->get_row($sql)){
				return new static($object, false);
			}
			return null;
		}

		static function all($args=array()){
			global $wpdb ; $objects = array() ;
			
			if(isset($args['where'])){
				$where = array() ; $i = 0 ; 
				foreach ($args['where'] as $param => $value) {
					if(static::is_text_field($param)) $value = "\"$value\"" ;
					if(stripos($param, 'or ') === false && $i >= 1){
						$separator = 'and ' ;
					} else { $separator = '' ; } $i++ ;
					$operator = " = " ;
					$where[]= "$separator $param $operator $value" ; 
				}
				$where = 'where ' . implode(' ', $where) ;
			} else { $where = '' ;}

			if(isset($args['fields'])) {
				$fields = implode(', ', $args['fields']) ;
			} else { $fields = '*' ; }

			if(isset($args['count'])) $fields = 'count(id)' ;

			$order_by = isset($args['order_by']) ? 'order by '.$args['order_by'] : '' ;

			if(isset($args['join'])){
				if(post_type_exists($args['join'])){
					$fk = $args['join'] . '_id' ;
					$join = "inner join $wpdb->posts post on post.ID = $fk" ;
				}
			} else { $join = ''; }

			$sql = "select $fields from " . static::table_name() . " $join $where $order_by" ;
			if( ! isset($args['count'])){
				foreach ($wpdb->get_results($sql, ARRAY_A) as $obj) {
					$objects[]= new static($obj, false) ;
				}
			} else {
				$objects = $wpdb->get_var($sql) ;
			}
			return $objects ; 

		}
		
		
	}

?>