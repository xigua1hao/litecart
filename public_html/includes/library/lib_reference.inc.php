<?php

  class reference {

    private static $_cache;

    public static function __callStatic($resource, $arguments) {

      if (!isset($arguments[0])) {
        trigger_error('Passed argument cannot be empty', E_USER_WARNING);
        return;
      }

      $checksum = md5(json_encode($arguments, JSON_UNESCAPED_SLASHES));

      if (isset(self::$_cache[$resource][$checksum])) {
        return self::$_cache[$resource][$checksum];
      }

      if (isset(self::$_cache[$resource]) && count(self::$_cache[$resource]) >= 100) {
        array_shift(self::$_cache[$resource]);
      }

      $component = null;
      if (preg_match('#^(ref|ent)_#', $resource, $matches)) {
        $component = $matches[1];
        $resource = preg_replace('#^'. preg_quote($component, '#') .'_(.*)$#', '$1', $resource);
      }

      switch(true) {
        case ($component == 'ref'):
        case (!$component && is_file(vmod::check(FS_DIR_APP . 'includes/references/ref_'.basename($resource).'.inc.php'))):

          $class_name = 'ref_'.$resource;

          //self::$_cache[$resource][$checksum] = new $class_name(...$arguments); // As of PHP 5.6
          self::$_cache[$resource][$checksum] = new $class_name(
            isset($arguments[0]) ? $arguments[0] : null,
            isset($arguments[1]) ? $arguments[1] : null,
            isset($arguments[2]) ? $arguments[2] : null
          );

          call_user_func_array(array(self::$_cache[$resource][$checksum], '__construct'), $arguments);

          return self::$_cache[$resource][$checksum];

        case ($component == 'ent'):
        case (!$component && is_file(vmod::check(FS_DIR_APP . 'includes/entities/ent_'.basename($resource).'.inc.php'))):

          $class_name = 'ent_'.$resource;
          $object = new $class_name($arguments[0]);

          self::$_cache[$resource][$checksum] = new StdClass;

          if (!empty($object->data['id'])) {
            foreach ($object->data as $key => $value) self::$_cache[$resource][$checksum]->$key = $value;
          }

          return self::$_cache[$resource][$checksum];

        default:

          self::$_cache[$resource][$checksum] = null;
          trigger_error('Unsupported data object ('.$resource.')', E_USER_ERROR);
      }
    }
  }
