<?php

  class ent_product {
    public $data;
    public $previous;

    public function __construct($product_id=null) {

      if (!empty($product_id)) {
        $this->load($product_id);
      } else {
        $this->reset();
      }
    }

    public function reset() {

      $this->data = array();

      $fields_query = database::query(
        "show fields from ". DB_TABLE_PRODUCTS .";"
      );

      while ($field = database::fetch($fields_query)) {
        $this->data[$field['Field']] = null;
      }

      $info_fields_query = database::query(
        "show fields from ". DB_TABLE_PRODUCTS_INFO .";"
      );

      while ($field = database::fetch($info_fields_query)) {
        if (in_array($field['Field'], array('id', 'product_id', 'language_code'))) continue;

        $this->data[$field['Field']] = array();
        foreach (array_keys(language::$languages) as $language_code) {
          $this->data[$field['Field']][$language_code] = null;
        }
      }

      $this->data['categories'] = array();
      $this->data['attributes'] = array();
      $this->data['keywords'] = array();
      $this->data['images'] = array();
      $this->data['prices'] = array();
      $this->data['campaigns'] = array();
      $this->data['options'] = array();
      $this->data['options_stock'] = array();

      $this->previous = $this->data;
    }

    public function load($product_id) {

      if (!preg_match('#^[0-9]+$#', $product_id)) throw new Exception('Invalid product (ID: '. $product_id .')');

      $this->reset();

    // Product
      $products_query = database::query(
        "select * from ". DB_TABLE_PRODUCTS ."
        where id = ". (int)$product_id ."
        limit 1;"
      );

      if ($product = database::fetch($products_query)) {
        $this->data = array_replace($this->data, array_intersect_key($product, $this->data));
      } else {
        throw new Exception('Could not find product (ID: '. (int)$product_id .') in database.');
      }

      foreach ($product as $key => $value) {
        $this->data[$key] = $value;
      }

      $this->data['keywords'] = !empty($this->data['keywords']) ? explode(',', $this->data['keywords']) : array();

    // Categories
      $categories_query = database::query(
        "select category_id from ". DB_TABLE_PRODUCTS_TO_CATEGORIES ."
         where product_id = ". (int)$product_id .";"
      );

      while ($category = database::fetch($categories_query)) {
        $this->data['categories'][] = $category['category_id'];
      }

    // Info
      $products_info_query = database::query(
        "select * from ". DB_TABLE_PRODUCTS_INFO ."
         where product_id = ". (int)$product_id .";"
      );

      while ($product_info = database::fetch($products_info_query)) {
        foreach ($product_info as $key => $value) {
          if (in_array($key, array('id', 'product_id', 'language_code'))) continue;
          $this->data[$key][$product_info['language_code']] = $value;
        }
      }

    // Attributes
      $product_attributes_query = database::query(
        "select pa.*, agi.name as group_name, avi.name as value_name from ". DB_TABLE_PRODUCTS_ATTRIBUTES ." pa
        left join ". DB_TABLE_ATTRIBUTE_GROUPS_INFO ." agi on (agi.group_id = pa.group_id and agi.language_code = '". database::input(language::$selected['code']) ."')
        left join ". DB_TABLE_ATTRIBUTE_VALUES_INFO ." avi on (avi.value_id = pa.value_id and avi.language_code = '". database::input(language::$selected['code']) ."')
        where product_id = ". (int)$product_id ."
        order by group_name, value_name, custom_value;"
      );

      while ($attribute = database::fetch($product_attributes_query)) {
        $this->data['attributes'][$attribute['group_id'].'-'.$attribute['value_id']] = $attribute;
      }

    // Prices
      $products_prices_query = database::query(
        "select * from ". DB_TABLE_PRODUCTS_PRICES ."
        where product_id = ". (int)$this->data['id'] .";"
      );

      while ($product_price = database::fetch($products_prices_query)) {
        foreach (array_keys(currency::$currencies) as $currency_code) {
          $this->data['prices'][$currency_code] = $product_price[$currency_code];
        }
      }

    // Campaigns
      $product_campaigns_query = database::query(
        "select * from ". DB_TABLE_PRODUCTS_CAMPAIGNS ."
        where product_id = ". (int)$this->data['id'] ."
        order by start_date;"
      );

      while ($product_campaign = database::fetch($product_campaigns_query)) {
        $this->data['campaigns'][$product_campaign['id']] = $product_campaign;
      }

    // Options stock
      $products_options_stock_query = database::query(
        "select * from ". DB_TABLE_PRODUCTS_OPTIONS_STOCK ."
        where product_id = ". (int)$this->data['id'] ."
        order by priority;"
      );

      while ($option_stock = database::fetch($products_options_stock_query)) {

        $this->data['options_stock'][$option_stock['id']] = $option_stock;
        $this->data['options_stock'][$option_stock['id']]['name'] = array();

        foreach (explode(',', $option_stock['combination']) as $combination) {
          list($group_id, $value_id) = explode('-', $combination);

          $options_values_query = database::query(
            "select ovi.value_id, ovi.name, ovi.language_code from ". DB_TABLE_OPTION_VALUES_INFO ." ovi
            where ovi.value_id = ". (int)$value_id .";"
          );

          while ($option_value = database::fetch($options_values_query)) {
            if (!isset($this->data['options_stock'][$option_stock['id']]['name'][$option_value['language_code']])) {
              $this->data['options_stock'][$option_stock['id']]['name'][$option_value['language_code']] = '';
            } else {
              $this->data['options_stock'][$option_stock['id']]['name'][$option_value['language_code']] .= ', ';
            }
            $this->data['options_stock'][$option_stock['id']]['name'][$option_value['language_code']] .= $option_value['name'];
          }
        }
      }

    // Images
      $products_images_query = database::query(
        "select * from ". DB_TABLE_PRODUCTS_IMAGES."
        where product_id = ". (int)$this->data['id'] ."
        order by priority asc, id asc;"
      );

      while ($image = database::fetch($products_images_query)) {
        $this->data['images'][$image['id']] = $image;
      }

      $this->previous = $this->data;
    }

    public function save() {

      if (empty($this->data['id'])) {
        database::query(
          "insert into ". DB_TABLE_PRODUCTS ."
          (date_created)
          values ('". ($this->data['date_created'] = date('Y-m-d H:i:s')) ."');"
        );
        $this->data['id'] = database::insert_id();
      }

    // Calculate product quantity from options
      if (!empty($this->data['options_stock'])) {
        $this->data['quantity'] = 0;

        foreach ($this->data['options_stock'] as $option) {
          $this->data['quantity'] += @$option['quantity'];
        }
      }

      $this->data['categories'] = array_map('trim', $this->data['categories']);
      $this->data['categories'] = array_filter($this->data['categories'], function($var) { return ($var != ''); }); // Don't filter root ('0')
      $this->data['categories'] = array_unique($this->data['categories']);

      $this->data['keywords'] = array_map('trim', $this->data['keywords']);
      $this->data['keywords'] = array_filter($this->data['keywords']);
      $this->data['keywords'] = array_unique($this->data['keywords']);

      if (empty($this->data['default_category_id']) || !in_array($this->data['default_category_id'], $this->data['categories'])) {
        $this->data['default_category_id'] = reset($this->data['categories']);
      }

      database::query(
        "update ". DB_TABLE_PRODUCTS ." set
        status = ". (int)$this->data['status'] .",
        manufacturer_id = ". (int)$this->data['manufacturer_id'] .",
        supplier_id = ". (int)$this->data['supplier_id'] .",
        delivery_status_id = ". (int)$this->data['delivery_status_id'] .",
        sold_out_status_id = ". (int)$this->data['sold_out_status_id'] .",
        default_category_id = ". (int)$this->data['default_category_id'] .",
        keywords = '". database::input(implode(',', $this->data['keywords'])) ."',
        quantity = ". (float)$this->data['quantity'] .",
        quantity_unit_id = ". (int)$this->data['quantity_unit_id'] .",
        purchase_price = ". (float)$this->data['purchase_price'] .",
        purchase_price_currency_code = '". database::input($this->data['purchase_price_currency_code']) ."',
        tax_class_id = ". (int)$this->data['tax_class_id'] .",
        code = '". database::input($this->data['code']) ."',
        sku = '". database::input($this->data['sku']) ."',
        mpn = '". database::input($this->data['mpn']) ."',
        gtin = '". database::input($this->data['gtin']) ."',
        taric = '". database::input($this->data['taric']) ."',
        dim_x = ". (float)$this->data['dim_x'] .",
        dim_y = ". (float)$this->data['dim_y'] .",
        dim_z = ". (float)$this->data['dim_z'] .",
        dim_class = '". database::input($this->data['dim_class']) ."',
        weight = ". (float)$this->data['weight'] .",
        weight_class = '". database::input($this->data['weight_class']) ."',
        date_valid_from = '". database::input($this->data['date_valid_from']) ."',
        date_valid_to = '". database::input($this->data['date_valid_to']) ."',
        date_updated = '". ($this->data['date_updated'] = date('Y-m-d H:i:s')) ."'
        where id = ". (int)$this->data['id'] ."
        limit 1;"
      );

    // Categories
      database::query(
        "delete from " . DB_TABLE_PRODUCTS_TO_CATEGORIES . "
        where product_id = ". (int)$this->data['id'] ."
        and category_id not in ('". @implode("', '", database::input($this->data['categories'])) ."');"
      );

      foreach ($this->data['categories'] as $category_id) {
        if (in_array($category_id, $this->previous['categories'])) continue;
        database::query(
          "insert into ". DB_TABLE_PRODUCTS_TO_CATEGORIES ."
          (product_id, category_id)
          values (". (int)$this->data['id'] .", ". (int)$category_id .");"
        );
      }

    // Info
      foreach (array_keys(language::$languages) as $language_code) {
        $products_info_query = database::query(
          "select * from ". DB_TABLE_PRODUCTS_INFO ."
          where product_id = ". (int)$this->data['id'] ."
          and language_code = '". database::input($language_code) ."'
          limit 1;"
        );

        if (!$product_info = database::fetch($products_info_query)) {
          database::query(
            "insert into ". DB_TABLE_PRODUCTS_INFO ."
            (product_id, language_code)
            values (". (int)$this->data['id'] .", '". database::input($language_code) ."');"
          );
        }

        database::query(
          "update ". DB_TABLE_PRODUCTS_INFO ." set
          name = '". database::input($this->data['name'][$language_code]) ."',
          short_description = '". database::input($this->data['short_description'][$language_code]) ."',
          description = '". database::input($this->data['description'][$language_code], true) ."',
          technical_data = '". database::input($this->data['technical_data'][$language_code], true) ."',
          head_title = '". database::input($this->data['head_title'][$language_code]) ."',
          meta_description = '". database::input($this->data['meta_description'][$language_code]) ."'
          where product_id = ". (int)$this->data['id'] ."
          and language_code = '". database::input($language_code) ."'
          limit 1;"
        );
      }

    // Attributes
      database::query(
        "delete from ". DB_TABLE_PRODUCTS_ATTRIBUTES ."
        where product_id = ". (int)$this->data['id'] ."
        and id not in ('". @implode("', '", array_column($this->data['attributes'], 'id')) ."');"
      );

      if (!empty($this->data['attributes'])) {
        foreach (array_keys($this->data['attributes']) as $key) {
          if (empty($this->data['attributes'][$key]['id'])) {
            database::query(
              "insert into ". DB_TABLE_PRODUCTS_ATTRIBUTES ."
              (product_id, group_id, value_id, custom_value)
              values (". (int)$this->data['id'] .", ". (int)$this->data['attributes'][$key]['group_id'] .", ". (int)$this->data['attributes'][$key]['value_id'] .", '". database::input($this->data['attributes'][$key]['custom_value']) ."');"
            );
            $this->data['attributes'][$key]['id'] = database::insert_id();
          }

          database::query(
            "update ". DB_TABLE_PRODUCTS_ATTRIBUTES ." set
              group_id = ". (int)$this->data['attributes'][$key]['group_id'] .",
              value_id = ". (int)$this->data['attributes'][$key]['value_id'] .",
              custom_value = '". database::input($this->data['attributes'][$key]['custom_value']) ."'
            where product_id = ". (int)$this->data['id'] ."
            and id = ". (int)$this->data['attributes'][$key]['id'] ."
            limit 1;"
          );
        }
      }

    // Prices
      foreach (array_keys(currency::$currencies) as $currency_code) {

        $products_prices_query = database::query(
          "select * from ". DB_TABLE_PRODUCTS_PRICES ."
          where product_id = ". (int)$this->data['id'] ."
          limit 1;"
        );

        if (!$product_price = database::fetch($products_prices_query)) {
          database::query(
            "insert into ". DB_TABLE_PRODUCTS_PRICES ."
            (product_id)
            values (". (int)$this->data['id'] .");"
          );
        }

        $sql_currency_prices = "";
        foreach (array_keys(currency::$currencies) as $currency_code) {
          $sql_currency_prices .= $currency_code ." = '". (!empty($this->data['prices'][$currency_code]) ? (float)$this->data['prices'][$currency_code] : 0) ."', ";
        }
        $sql_currency_prices = rtrim($sql_currency_prices, ', ');

        database::query(
          "update ". DB_TABLE_PRODUCTS_PRICES ." set
          $sql_currency_prices
          where product_id = ". (int)$this->data['id'] ."
          limit 1;"
        );
      }

    // Delete campaigns
      database::query(
        "delete from ". DB_TABLE_PRODUCTS_CAMPAIGNS ."
        where product_id = ". (int)$this->data['id'] ."
        and id not in ('". @implode("', '", array_column($this->data['campaigns'], 'id')) ."');"
      );

    // Update campaigns
      if (!empty($this->data['campaigns'])) {
        foreach (array_keys($this->data['campaigns']) as $key) {
          if (empty($this->data['campaigns'][$key]['id'])) {
            database::query(
              "insert into ". DB_TABLE_PRODUCTS_CAMPAIGNS ."
              (product_id)
              values (". (int)$this->data['id'] .");"
            );
            $this->data['campaigns'][$key]['id'] = database::insert_id();
          }

          $sql_currency_campaigns = "";
          foreach (array_keys(currency::$currencies) as $currency_code) {
            $sql_currency_campaigns .= $currency_code ." = '". (float)$this->data['campaigns'][$key][$currency_code] ."', ";
          }
          $sql_currency_campaigns = rtrim($sql_currency_campaigns, ', ');

          database::query(
            "update ". DB_TABLE_PRODUCTS_CAMPAIGNS ." set
            start_date = ". (empty($this->data['campaigns'][$key]['start_date']) ? "NULL" : "'". date('Y-m-d H:i:s', strtotime($this->data['campaigns'][$key]['start_date'])) ."'") .",
            end_date = ". (empty($this->data['campaigns'][$key]['end_date']) ? "NULL" : "'". date('Y-m-d H:i:s', strtotime($this->data['campaigns'][$key]['end_date'])) ."'") .",
            $sql_currency_campaigns
            where product_id = ". (int)$this->data['id'] ."
            and id = ". (int)$this->data['campaigns'][$key]['id'] ."
            limit 1;"
          );
        }
      }

    // Delete stock options
      database::query(
        "delete from ". DB_TABLE_PRODUCTS_OPTIONS_STOCK ."
        where product_id = ". (int)$this->data['id'] ."
        and id not in ('". @implode("', '", array_column($this->data['options_stock'], 'id')) ."');"
      );

    // Update stock options
      if (!empty($this->data['options_stock'])) {
        $i = 0;
        foreach (array_keys($this->data['options_stock']) as $key) {
          if (empty($this->data['options_stock'][$key]['id'])) {
            database::query(
              "insert into ". DB_TABLE_PRODUCTS_OPTIONS_STOCK ."
              (product_id, date_created)
              values (". (int)$this->data['id'] .", '". date('Y-m-d H:i:s') ."');"
            );
            $this->data['options_stock'][$key]['id'] = database::insert_id();
          }

        // Ascending option combination
          $combinations = explode(',', $this->data['options_stock'][$key]['combination']);

          usort($combinations, function($a, $b) {
            $a = explode('-', $a);
            $b = explode('-', $b);
            if ($a[0] == $b[0]) {
              return ($a[1] < $b[1]) ? -1 : 1;
            }
            return ($a[0] < $b[0]) ? -1 : 1;
          });

          $this->data['stock_options'][$key]['combination'] = implode(',', $combinations);

          database::query(
            "update ". DB_TABLE_PRODUCTS_OPTIONS_STOCK ."
            set combination = '". database::input($this->data['options_stock'][$key]['combination']) ."',
            sku = '". database::input($this->data['options_stock'][$key]['sku']) ."',
            weight = '". database::input($this->data['options_stock'][$key]['weight']) ."',
            weight_class = '". database::input($this->data['options_stock'][$key]['weight_class']) ."',
            dim_x = '". database::input($this->data['options_stock'][$key]['dim_x']) ."',
            dim_y = '". database::input($this->data['options_stock'][$key]['dim_y']) ."',
            dim_z = '". database::input($this->data['options_stock'][$key]['dim_z']) ."',
            dim_class = '". database::input($this->data['options_stock'][$key]['dim_class']) ."',
            quantity = '". database::input($this->data['options_stock'][$key]['quantity']) ."',
            priority = '". $i++ ."',
            date_updated = '". ($this->data['date_updated'] = date('Y-m-d H:i:s')) ."'
            where product_id = ". (int)$this->data['id'] ."
            and id = ". (int)$this->data['options_stock'][$key]['id'] ."
            limit 1;"
          );
        }
      }

    // Delete images
      $products_images_query = database::query(
        "select * from ". DB_TABLE_PRODUCTS_IMAGES ."
        where product_id = ". (int)$this->data['id'] ."
        and id not in ('". @implode("', '", array_column($this->data['images'], 'id')) ."');"
      );

      while ($product_image = database::fetch($products_images_query)) {
        if (is_file(FS_DIR_APP . 'images/' . $product_image['filename'])) {
          unlink(FS_DIR_APP . 'images/' . $product_image['filename']);
        }

        functions::image_delete_cache(FS_DIR_APP . 'images/' . $product_image['filename']);

        database::query(
          "delete from ". DB_TABLE_PRODUCTS_IMAGES ."
          where product_id = ". (int)$this->data['id'] ."
          and id = ". (int)$product_image['id'] ."
          limit 1;"
        );
      }

    // Update images
      if (!empty($this->data['images'])) {
        $image_priority = 1;

        foreach (array_keys($this->data['images']) as $key) {
          if (empty($this->data['images'][$key]['id'])) {
            database::query(
              "insert into ". DB_TABLE_PRODUCTS_IMAGES ."
              (product_id)
              values (". (int)$this->data['id'] .");"
            );
            $this->data['images'][$key]['id'] = database::insert_id();
          }

          if (!empty($this->data['images'][$key]['new_filename']) && !is_file(FS_DIR_APP . 'images/' . $this->data['images'][$key]['new_filename'])) {
            functions::image_delete_cache(FS_DIR_APP . 'images/' . $this->data['images'][$key]['filename']);
            functions::image_delete_cache(FS_DIR_APP . 'images/' . $this->data['images'][$key]['new_filename']);
            rename(FS_DIR_APP . 'images/' . $this->data['images'][$key]['filename'], FS_DIR_APP . 'images/' . $this->data['images'][$key]['new_filename']);
            $this->data['images'][$key]['filename'] = $this->data['images'][$key]['new_filename'];
          }

          database::query(
            "update ". DB_TABLE_PRODUCTS_IMAGES ."
            set filename = '". database::input($this->data['images'][$key]['filename']) ."',
                priority = '". $image_priority++ ."'
            where product_id = ". (int)$this->data['id'] ."
            and id = ". (int)$this->data['images'][$key]['id'] ."
            limit 1;"
          );
        }
      }

    // Update product image
      if (!empty($this->data['images'])) {
        $images = array_values($this->data['images']);
        $image = array_shift($images);
        $this->data['image'] = $image['filename'];
      } else {
        $this->data['image'];
      }

      database::query(
        "update ". DB_TABLE_PRODUCTS ." set
        image = '". database::input($this->data['image']) ."'
        where id=". (int)$this->data['id'] ."
        limit 1;"
      );

      $this->previous = $this->data;

      cache::clear_cache('product_'.$this->data['id']);
      cache::clear_cache('products');
    }

    public function add_image($file, $filename='') {

      if (empty($file)) return;

      $checksum = md5_file($file);
      if (in_array($checksum, array_column($this->data['images'], 'checksum'))) return false;

      if (!empty($filename)) $filename = 'products/' . $filename;

      if (empty($this->data['id'])) {
        $this->save();
      }

      if (!is_dir(FS_DIR_APP . 'images/products/')) mkdir(FS_DIR_APP . 'images/products/', 0777);

      if (!$image = new ent_image($file)) return false;

    // 456-Fancy-product-title-N.jpg
      $i=1;
      while (empty($filename) || is_file(FS_DIR_APP . 'images/' . $filename)) {
        $filename = 'products/' . $this->data['id'] .'-'. functions::general_path_friendly($this->data['name'][settings::get('store_language_code')], settings::get('store_language_code')) .'-'. $i++ .'.'. $image->type();
      }

      $priority = count($this->data['images'])+1;

      if (settings::get('image_downsample_size')) {
        list($width, $height) = explode(',', settings::get('image_downsample_size'));
        $image->resample($width, $height, 'FIT_ONLY_BIGGER');
      }

      if (!$image->write(FS_DIR_APP . 'images/' . $filename, '', 90)) return false;

      functions::image_delete_cache(FS_DIR_APP . 'images/' . $filename);

      database::query(
        "insert into ". DB_TABLE_PRODUCTS_IMAGES ."
        (product_id, filename, checksum, priority)
        values (". (int)$this->data['id'] .", '". database::input($filename) ."', '". database::input($checksum) ."', ". (int)$priority .");"
      );
      $image_id = database::insert_id();

      $this->data['images'][$image_id] = array(
        'id' => $image_id,
        'filename' => $filename,
        'checksum' => $checksum,
        'priority' => $priority,
      );

      $this->previous['images'][$image_id] = $this->data['images'][$image_id];
    }

    public function delete() {

      if (empty($this->data['id'])) return;

      $this->data['images'] = array();
      $this->data['campaigns'] = array();
      $this->data['options'] = array();
      $this->data['options_stock'] = array();
      $this->save();

      database::query(
        "delete from ". DB_TABLE_PRODUCTS ."
        where id = ". (int)$this->data['id'] ."
        limit 1;"
      );

      database::query(
        "delete from ". DB_TABLE_PRODUCTS_INFO ."
        where product_id = ". (int)$this->data['id'] .";"
      );
      database::query(
        "delete from ". DB_TABLE_PRODUCTS_TO_CATEGORIES ."
         where product_id = ". (int)$this->data['id'] .";"
      );
      database::query(
        "delete from ". DB_TABLE_PRODUCTS_PRICES ."
        where product_id = ". (int)$this->data['id'] .";"
      );

      database::query(
        "delete from ". DB_TABLE_PRODUCTS_CAMPAIGNS ."
        where product_id = ". (int)$this->data['id'] .";"
      );

      $this->reset();

      cache::clear_cache('products');
    }
  }
