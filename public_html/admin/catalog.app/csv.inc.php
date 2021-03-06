<?php
  breadcrumbs::add(language::translate('title_import_export_csv', 'Import/Export CSV'));

  if (isset($_POST['import_categories'])) {

    try {
      if (!isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        throw new Exception(language::translate('error_must_select_file_to_upload', 'You must select a file to upload'));
      }

      ob_clean();

      header('Content-Type: text/plain; charset='. language::$selected['charset']);

      echo "CSV Import\r\n"
         . "----------\r\n";

      $csv = file_get_contents($_FILES['file']['tmp_name']);

      if (!$csv = functions::csv_decode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset'])) {
        throw new Exception(language::translate('error_failed_decoding_csv', 'Failed decoding CSV'));
      }

      $line = 0;
      foreach ($csv as $row) {
        $line++;

      // Find category
        if (!empty($row['id'])) {
          if ($category = database::fetch(database::query("select id from ". DB_TABLE_CATEGORIES ." where id = ". (int)$row['id'] ." limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing category on $line\r\n";
              continue;
            }
            $category = new ent_category($category['id']);
            echo "Updating existing category ". (!empty($row['name']) ? $row['name'] : "") ." matching id ". $row['id'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New category on line $line was not inserted to database.\r\n";
              continue;
            }
            database::query("insert into ". DB_TABLE_CATEGORIES ." (id, date_created) values (". (int)$row['id'] .", '". date('Y-m-d H:i:s') ."');");
            $category = new ent_category($row['id']);
            echo 'Creating new category: '. $row['name'] . PHP_EOL;
          }

        } elseif (!empty($row['code'])) {
          if ($category = database::fetch(database::query("select id from ". DB_TABLE_CATEGORIES ." where code = '". database::input($row['code']) ."' limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing category on $line\r\n";
              continue;
            }
            $category = new ent_category($category['id']);
            echo "Updating existing category ". (!empty($row['name']) ? $row['name'] : "") ." matching code ". $row['code'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New category on line $line was not inserted to database.\r\n";
              continue;
            }
            $category = new ent_category();
            echo 'Creating new category: '. $row['name'] . PHP_EOL;
          }

        } elseif (!empty($row['name']) && !empty($row['language_code'])) {
          if ($category = database::fetch(database::query("select category_id as id from ". DB_TABLE_CATEGORIES_INFO ." where name = '". database::input($row['name']) ."' and language_code = '". database::input($row['language_code']) ."' limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing category on $line\r\n";
              continue;
            }
            $category = new ent_category($category['id']);
            echo "Updating existing category ". (!empty($row['name']) ? $row['name'] : "") ." matching name ". $row['name'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New category on line $line was not inserted to database.\r\n";
              continue;
            }
            $category = new ent_category();
          }

        } else {
          echo "[Skipped] Could not identify category on line $line.\r\n";
          continue;
        }

      // Set new category data
        $fields = array(
          'parent_id',
          'status',
          'code',
          'keywords',
          'image',
          'priority',
        );

        foreach ($fields as $field) {
          if (isset($row[$field])) $category->data[$field] = $row[$field];
        }

      // Set category info data
        if (!empty($row['language_code'])) {
          $fields = array(
            'name',
            'short_description',
            'description',
            'head_title',
            'h1_title',
            'meta_description',
          );

          foreach ($fields as $field) {
            if (isset($row[$field])) $category->data[$field][$row['language_code']] = $row[$field];
          }
        }

        if (isset($row['new_image'])) {
          $category->save_image($row['new_image']);
        }

        $category->save();

        if (!empty($row['date_created'])) {
          database::query(
            "update ". DB_TABLE_CATEGORIES ."
            set date_created = '". date('Y-m-d H:i:s', strtotime($row['date_created'])) ."'
            where id = ". (int)$category->data['id'] ."
            limit 1;"
          );
        }
      }

      exit;

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }

  if (isset($_POST['export_categories'])) {

    try {
      if (empty($_POST['language_code'])) throw new Exception(language::translate('error_must_select_a_language', 'You must select a language'));

      $csv = array();

      $categories_query = database::query("select id from ". DB_TABLE_CATEGORIES ." order by parent_id;");
      while ($category = database::fetch($categories_query)) {
        $category = new ref_category($category['id'], $_POST['language_code']);

        $csv[] = array(
          'id' => $category->id,
          'status' => $category->status,
          'parent_id' => $category->parent_id,
          'code' => $category->code,
          'name' => $category->name,
          'keywords' => implode(',', $category->keywords),
          'short_description' => $category->short_description,
          'description' => $category->description,
          'meta_description' => $category->meta_description,
          'head_title' => $category->head_title,
          'h1_title' => $category->h1_title,
          'image' => $category->image,
          'priority' => $category->priority,
          'language_code' => $_POST['language_code'],
        );
      }

      ob_clean();

      if ($_POST['output'] == 'screen') {
        header('Content-Type: text/plain; charset='. $_POST['charset']);
      } else {
        header('Content-Type: application/csv; charset='. $_POST['charset']);
        header('Content-Disposition: attachment; filename=categories-'. $_POST['language_code'] .'.csv');
      }

      switch($_POST['eol']) {
        case 'Linux':
          echo functions::csv_encode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset'], "\r");
          break;
        case 'Mac':
          echo functions::csv_encode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset'], "\n");
          break;
        case 'Win':
        default:
          echo functions::csv_encode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset'], "\r\n");
          break;
      }

      exit;

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }

  if (isset($_POST['import_products'])) {

    try {

      if (!isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        throw new Exception(language::translate('error_must_select_file_to_upload', 'You must select a file to upload'));
      }

      ob_clean();

      header('Content-Type: text/plain; charset='. language::$selected['charset']);

      echo "CSV Import\r\n"
         . "----------\r\n";

      $csv = file_get_contents($_FILES['file']['tmp_name']);

      $csv = functions::csv_decode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset']);

      $line = 0;
      foreach ($csv as $row) {
        $line++;

      // Find product
        if (!empty($row['id'])) {
          if ($product = database::fetch(database::query("select id from ". DB_TABLE_PRODUCTS ." where id = ". (int)$row['id'] ." limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing product on $line\r\n";
              continue;
            }
            $product = new ent_product($product['id']);
            echo "Updating existing product ". (!empty($row['name']) ? $row['name'] : "") ." matching id ". $row['id'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New product on line $line was not inserted to database.\r\n";
              continue;
            }
            database::query("insert into ". DB_TABLE_PRODUCTS ." (id, date_created) values (". (int)$row['id'] .", '". date('Y-m-d H:i:s') ."');");
            $product = new ent_product($row['id']);
            echo 'Creating new product: '. $row['name'] . PHP_EOL;
          }

        } elseif (!empty($row['code'])) {
          if ($product = database::fetch(database::query("select id from ". DB_TABLE_PRODUCTS ." where code = '". database::input($row['code']) ."' limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing product on $line\r\n";
              continue;
            }
            $product = new ent_product($product['id']);
            echo "Updating existing product ". (!empty($row['name']) ? $row['name'] : "") ." matching code ". $row['code'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New product on line $line was not inserted to database.\r\n";
              continue;
            }
            $product = new ent_product();
            echo 'Creating new product: '. $row['name'] . PHP_EOL;
          }

        } elseif (!empty($row['sku'])) {
          if ($product = database::fetch(database::query("select id from ". DB_TABLE_PRODUCTS ." where sku = '". database::input($row['sku']) ."' limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing product on $line\r\n";
              continue;
            }
            $product = new ent_product($product['id']);
            echo "Updating existing product ". (!empty($row['name']) ? $row['name'] : "") ." matching SKU ". $row['sku'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New product on line $line was not inserted to database.\r\n";
              continue;
            }
            $product = new ent_product();
            echo 'Creating new product: '. $row['name'] . PHP_EOL;
          }

        } elseif (!empty($row['mpn'])) {
          if ($product = database::fetch(database::query("select id from ". DB_TABLE_PRODUCTS ." where mpn = '". database::input($row['mpn']) ."' limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing product on $line\r\n";
              continue;
            }
            $product = new ent_product($product['id']);
            echo "Updating existing product ". (!empty($row['name']) ? $row['name'] : "") ." matching MPN ". $row['mpn'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New product on line $line was not inserted to database.\r\n";
              continue;
            }
            $product = new ent_product();
            echo 'Creating new product: '. $row['name'] . PHP_EOL;
          }

        } elseif (!empty($row['gtin'])) {
          if ($product = database::fetch(database::query("select id from ". DB_TABLE_PRODUCTS ." where gtin = '". database::input($row['gtin']) ."' limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing product on $line\r\n";
              continue;
            }
            $product = new ent_product($product['id']);
            echo "Updating existing product ". (!empty($row['name']) ? $row['name'] : "") ." matching GTIN ". $row['gtin'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New product on line $line was not inserted to database.\r\n";
              continue;
            }
            $product = new ent_product();
            echo 'Creating new product: '. $row['name'] . PHP_EOL;
          }

        } elseif (!empty($row['name']) && !empty($row['language_code'])) {
          if ($product = database::fetch(database::query("select product_id as id from ". DB_TABLE_PRODUCTS_INFO ." where name = '". database::input($row['name']) ."' and language_code = '". database::input($row['language_code']) ."' limit 1;"))) {
            if (empty($_POST['overwrite'])) {
              echo "[Skipped] Skipping updating existing product on $line\r\n";
              continue;
            }
            $product = new ent_product($product['id']);
            echo "Updating existing product ". (!empty($row['name']) ? $row['name'] : "") ." matching name ". $row['name'] ." on line $line\r\n";
          } else {
            if (empty($_POST['insert'])) {
              echo "[Skipped] New product on line $line was not inserted to database.\r\n";
              continue;
            }
            $product = new ent_product();
          }

        } else {
          echo "[Skipped] Could not identify product on line $line.\r\n";
          continue;
        }

        if (empty($row['manufacturer_id']) && !empty($row['manufacturer_name'])) {
          $manufacturers_query = database::query(
            "select * from ". DB_TABLE_MANUFACTURERS ."
            where name = '". database::input($row['manufacturer_name']) ."'
            limit 1;"
          );
          if ($manufacturer = database::fetch($manufacturers_query)) {
            $row['manufacturer_id'] = $manufacturer['id'];
          } else {
            $manufacturer = new ent_manufacturer();
            $manufacturer->data['name'] = $row['manufacturer_name'];
            $manufacturer->save();
            $row['manufacturer_id'] = $manufacturer->data['id'];
          }
        }

        if (empty($row['supplier_id']) && !empty($row['supplier_id'])) {
          $suppliers_query = database::query(
            "select * from ". DB_TABLE_SUPPLIERS ."
            where name = '". database::input($row['supplier_name']) ."'
            limit 1;"
          );
          if ($supplier = database::fetch($suppliers_query)) {
            $row['supplier_id'] = $supplier['id'];
          } else {
            $supplier = new ent_supplier();
            $supplier->data['name'] = $row['supplier_name'];
            $supplier->save();
            $row['supplier_id'] = $supplier->data['id'];
          }
        }

        $fields = array(
          'status',
          'manufacturer_id',
          'supplier_id',
          'code',
          'sku',
          'mpn',
          'gtin',
          'taric',
          'tax_class_id',
          'quantity',
          'quantity_unit_id',
          'weight',
          'weight_class',
          'dim_x',
          'dim_y',
          'dim_z',
          'dim_class',
          'purchase_price',
          'purchase_price_currency_code',
          'delivery_status_id',
          'sold_out_status_id',
          'date_valid_from',
          'date_valid_to'
        );

      // Set new product data
        foreach ($fields as $field) {
          if (isset($row[$field])) $product->data[$field] = $row[$field];
        }

        if (isset($row['keywords'])) $product->data['keywords'] = preg_split('#, ?#', $row['keywords']);
        if (isset($row['categories'])) $product->data['categories'] = preg_split('#, ?#', $row['categories']);

      // Set price
        if (!empty($row['currency_code'])) {
          if (isset($row['price'])) $product->data['prices'][$row['currency_code']] = $row['price'];
        }

      // Set product info data
        if (!empty($row['language_code'])) {

          $fields = array(
            'name',
            'short_description',
            'description',
            'technical_data',
            'head_title',
            'meta_description',
          );

          foreach ($fields as $field) {
            if (isset($row[$field])) $product->data[$field][$row['language_code']] = $row[$field];
          }
        }

        if (isset($row['images'])) {
          $row['images'] = explode(';', $row['images']);

          $product_images = array();
          $current_images = array();
          foreach ($product->data['images'] as $key => $image) {
            if (in_array($image['filename'], $row['images'])) {
              $product_images[$key] = $image;
              $current_images[] = $image['filename'];
            }
          }

          $i=0;
          foreach ($row['images'] as $image) {
            if (!in_array($image, $current_images)) {
              $product_images['new'.++$i] = array('filename' => $image);
            }
          }

          $product->data['images'] = $product_images;
        }

        if (isset($row['new_images'])) {
          foreach (explode(';', $row['new_images']) as $new_image) {
            $product->add_image($new_image);
          }
        }

        $product->save();

        if (!empty($row['date_created'])) {
          database::query(
            "update ". DB_TABLE_PRODUCTS ."
            set date_created = '". date('Y-m-d H:i:s', strtotime($row['date_created'])) ."'
            where id = ". (int)$product->data['id'] ."
            limit 1;"
          );
        }
      }

      exit;

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }

  if (isset($_POST['export_products'])) {

    try {

      if (empty($_POST['language_code'])) throw new Exception(language::translate('error_must_select_a_language', 'You must select a language'));

      $csv = array();

      $products_query = database::query(
        "select p.id from ". DB_TABLE_PRODUCTS ." p
        left join ". DB_TABLE_PRODUCTS_INFO ." pi on (pi.product_id = p.id and pi.language_code = '". database::input($_POST['language_code']) ."')
        order by pi.name;"
      );

      while ($product = database::fetch($products_query)) {
        $product = new ref_product($product['id'], $_POST['language_code'], $_POST['currency_code']);

        $csv[] = array(
          'id' => $product->id,
          'status' => $product->status,
          'categories' => implode(',', array_keys($product->categories)),
          'manufacturer_id' => $product->manufacturer_id,
          'supplier_id' => $product->supplier_id,
          'code' => $product->code,
          'sku' => $product->sku,
          'mpn' => $product->mpn,
          'gtin' => $product->gtin,
          'taric' => $product->taric,
          'name' => $product->name,
          'short_description' => $product->short_description,
          'description' => $product->description,
          'keywords' => implode(',', $product->keywords),
          'technical_data' => $product->technical_data,
          'head_title' => $product->head_title,
          'meta_description' => $product->meta_description,
          'images' => implode(';', $product->images),
          'purchase_price' => $product->purchase_price,
          'purchase_price_currency_code' => $product->purchase_price_currency_code,
          'price' => $product->price,
          'tax_class_id' => $product->tax_class_id,
          'quantity' => $product->quantity,
          'quantity_unit_id' => $product->quantity_unit['id'],
          'weight' => $product->weight,
          'weight_class' => $product->weight_class,
          'dim_x' => $product->dim_x,
          'dim_y' => $product->dim_y,
          'dim_z' => $product->dim_z,
          'dim_class' => $product->dim_class,
          'delivery_status_id' => $product->delivery_status_id,
          'sold_out_status_id' => $product->sold_out_status_id,
          'language_code' => $_POST['language_code'],
          'currency_code' => $_POST['currency_code'],
          'date_valid_from' => $product->date_valid_from,
          'date_valid_to' => $product->date_valid_to,
        );
      }

      ob_clean();

      if ($_POST['output'] == 'screen') {
        header('Content-Type: text/plain; charset='. $_POST['charset']);
      } else {
        header('Content-Type: application/csv; charset='. $_POST['charset']);
        header('Content-Disposition: attachment; filename=products-'. $_POST['language_code'] .'.csv');
      }

      switch($_POST['eol']) {
        case 'Linux':
          echo functions::csv_encode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset'], "\r");
          break;
        case 'Mac':
          echo functions::csv_encode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset'], "\n");
          break;
        case 'Win':
        default:
          echo functions::csv_encode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset'], "\r\n");
          break;
      }

      exit;

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }
?>
<div class="panel panel-app">
  <div class="panel-heading">
    <?php echo $app_icon; ?> <?php echo language::translate('title_csv_import_export', 'CSV Import/Export'); ?>
  </div>

  <ul class="nav nav-tabs">
    <li class="active"><a data-toggle="tab" href="#tab-import"><?php echo language::translate('title_import_from_csv', 'Import From CSV'); ?></a></li>
    <li><a data-toggle="tab" href="#tab-export"><?php echo language::translate('title_export_to_csv', 'Export To CSV'); ?></a></li>
  </ul>

  <div class="panel-body">
    <div class="tab-content" style="max-width: 980px;">
      <div id="tab-import" class="tab-pane active">

        <div class="row">
          <div class="col-md-6">
            <h2><?php echo language::translate('title_categories', 'Categories'); ?></h2>

            <?php echo functions::form_draw_form_begin('import_categories_form', 'post', '', true); ?>

              <div class="form-group">
                <label><?php echo language::translate('title_csv_file', 'CSV File'); ?></label>
                <?php echo functions::form_draw_file_field('file'); ?>
              </div>

              <div class="row">
                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_delimiter', 'Delimiter'); ?></label>
                  <?php echo functions::form_draw_select_field('delimiter', array(array(language::translate('title_auto', 'Auto') .' ('. language::translate('text_default', 'default') .')', ''), array(','),  array(';'), array('TAB', "\t"), array('|')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_enclosure', 'Enclosure'); ?></label>
                  <?php echo functions::form_draw_select_field('enclosure', array(array('" ('. language::translate('text_default', 'default') .')', '"')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_escape_character', 'Escape Character'); ?></label>
                  <?php echo functions::form_draw_select_field('escapechar', array(array('" ('. language::translate('text_default', 'default') .')', '"'), array('\\', '\\')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_charset', 'Charset'); ?></label>
                  <?php echo functions::form_draw_encodings_list('charset', !empty($_POST['charset']) ? true : 'UTF-8'); ?>
                </div>
              </div>

              <div class="form-group">
                <div class="checkbox"><label><?php echo functions::form_draw_checkbox('insert', '1', true); ?> <?php echo language::translate('text_insert_new_entries', 'Insert new entries'); ?></label></div>
                <div class="checkbox"><label><?php echo functions::form_draw_checkbox('overwrite', '1', true); ?> <?php echo language::translate('text_overwrite_existing_entries', 'Overwrite existing entries'); ?></label></div>
              </div>

              <?php echo functions::form_draw_button('import_categories', language::translate('title_import', 'Import'), 'submit'); ?>

              <?php echo functions::form_draw_form_end(); ?>
          </div>

          <div class="col-md-6">
            <h2><?php echo language::translate('title_products', 'Products'); ?></h2>

            <?php echo functions::form_draw_form_begin('import_products_form', 'post', '', true); ?>

              <div class="form-group">
                <label><?php echo language::translate('title_csv_file', 'CSV File'); ?></label>
                <?php echo functions::form_draw_file_field('file'); ?>
              </div>

              <div class="row">
                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_delimiter', 'Delimiter'); ?></label>
                  <?php echo functions::form_draw_select_field('delimiter', array(array(language::translate('title_auto', 'Auto') .' ('. language::translate('text_default', 'default') .')', ''), array(','),  array(';'), array('TAB', "\t"), array('|')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_enclosure', 'Enclosure'); ?></label>
                  <?php echo functions::form_draw_select_field('enclosure', array(array('" ('. language::translate('text_default', 'default') .')', '"')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_escape_character', 'Escape Character'); ?></label>
                  <?php echo functions::form_draw_select_field('escapechar', array(array('" ('. language::translate('text_default', 'default') .')', '"'), array('\\', '\\')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_charset', 'Charset'); ?></label>
                  <?php echo functions::form_draw_encodings_list('charset', !empty($_POST['charset']) ? true : 'UTF-8'); ?>
                </div>
              </div>

              <div class="form-group">
                <div class="checkbox"><label><?php echo functions::form_draw_checkbox('insert', '1', true); ?> <?php echo language::translate('text_insert_new_entries', 'Insert new entries'); ?></label></div>
                <div class="checkbox"><label><?php echo functions::form_draw_checkbox('overwrite', '1', true); ?> <?php echo language::translate('text_overwrite_existing_entries', 'Overwrite existing entries'); ?></label></div>
              </div>

              <?php echo functions::form_draw_button('import_products', language::translate('title_import', 'Import'), 'submit'); ?>

            <?php echo functions::form_draw_form_end(); ?>
          </div>
        </div>
      </div>

      <div id="tab-export" class="tab-pane">

        <div class="row">
          <div class="col-md-6">
            <h2><?php echo language::translate('title_categories', 'Categories'); ?></h2>

            <?php echo functions::form_draw_form_begin('export_categories_form', 'post'); ?>

              <div class="form-group">
                <label><?php echo language::translate('title_language', 'Language'); ?></label>
                <?php echo functions::form_draw_languages_list('language_code', true).' '; ?>
              </div>

              <div class="row">
                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_delimiter', 'Delimiter'); ?></label>
                  <?php echo functions::form_draw_select_field('delimiter', array(array(', ('. language::translate('text_default', 'default') .')', ','), array(';'), array('TAB', "\t"), array('|')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_enclosure', 'Enclosure'); ?></label>
                  <?php echo functions::form_draw_select_field('enclosure', array(array('" ('. language::translate('text_default', 'default') .')', '"')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_escape_character', 'Escape Character'); ?></label>
                  <?php echo functions::form_draw_select_field('escapechar', array(array('" ('. language::translate('text_default', 'default') .')', '"'), array('\\', '\\')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_charset', 'Charset'); ?></label>
                  <?php echo functions::form_draw_encodings_list('charset', !empty($_POST['charset']) ? true : 'UTF-8'); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_line_ending', 'Line Ending'); ?></label>
                  <?php echo functions::form_draw_select_field('eol', array(array('Win'), array('Mac'), array('Linux')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_output', 'Output'); ?></label>
                  <?php echo functions::form_draw_select_field('output', array(array(language::translate('title_file', 'File'), 'file'), array(language::translate('title_screen', 'Screen'), 'screen')), true); ?>
                </div>
              </div>

              <?php echo functions::form_draw_button('export_categories', language::translate('title_export', 'Export'), 'submit'); ?>

            <?php echo functions::form_draw_form_end(); ?>
          </div>

          <div class="col-md-6">
            <h2><?php echo language::translate('title_products', 'Products'); ?></h2>

            <?php echo functions::form_draw_form_begin('export_products_form', 'post'); ?>

              <div class="row">
                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_language', 'Language'); ?></label>
                  <?php echo functions::form_draw_languages_list('language_code', true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_currency', 'Currency'); ?></label>
                  <?php echo functions::form_draw_currencies_list('currency_code', true); ?>
                </div>
              </div>

              <div class="row">
                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_delimiter', 'Delimiter'); ?></label>
                  <?php echo functions::form_draw_select_field('delimiter', array(array(', ('. language::translate('text_default', 'default') .')', ','), array(';'), array('TAB', "\t"), array('|')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_enclosure', 'Enclosure'); ?></label>
                  <?php echo functions::form_draw_select_field('enclosure', array(array('" ('. language::translate('text_default', 'default') .')', '"')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_escape_character', 'Escape Character'); ?></label>
                  <?php echo functions::form_draw_select_field('escapechar', array(array('" ('. language::translate('text_default', 'default') .')', '"'), array('\\', '\\')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_charset', 'Charset'); ?></label>
                  <?php echo functions::form_draw_encodings_list('charset', !empty($_POST['charset']) ? true : 'UTF-8'); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_line_ending', 'Line Ending'); ?></label>
                  <?php echo functions::form_draw_select_field('eol', array(array('Win'), array('Mac'), array('Linux')), true); ?>
                </div>

                <div class="form-group col-sm-6">
                  <label><?php echo language::translate('title_output', 'Output'); ?></label>
                  <?php echo functions::form_draw_select_field('output', array(array(language::translate('title_file', 'File'), 'file'), array(language::translate('title_screen', 'Screen'), 'screen')), true); ?>
                </div>
              </div>

              <?php echo functions::form_draw_button('export_products', language::translate('title_export', 'Export'), 'submit'); ?>

            <?php echo functions::form_draw_form_end(); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
