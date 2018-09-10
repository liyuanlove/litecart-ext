<?php
// catalog->CSV Import/Export page and process import/export Categories or Products

/**
 * 导入分类
 * @param $csv csv文件
 * @param $isInsertNew 当数据不存在时是否插入新数据。如果true，当数据库找不到导入的数据时，则将新增数据到数据库.
 */
function importCategories($csv,$isInsertNew)
{
  // import_categories start
  //if (isset($_POST['import_categories'])) {
//        try {

  $line = 0;
  foreach ($csv as $row) {//遍历csv文件
    $line++;
    // Find category， 这一段逻辑是根据上传的csv来创建category对象，为存在的分类将会添加到库，find 完成后会进行update
    if (!empty($row['category_id'])) {
      // 如果当前行id不为空，查询lc_categories表里对应的id数据是否存在，这里做了limit 1处理。
      // 如果能找到改id，则new ctrl_category对象，如果id在数据库不存在，会根据insert_categories参数来决定是否创建新的分类。
      // 当这个参数没有时，将不会创建一个新的分类，并且跳过当前这行数据。【注意：这里不会跳出整个csv，只是跳出当前行数据】
      if ($category = database::fetch(database::query("select id from " . DB_TABLE_CATEGORIES . " where id = " . (int)$row['category_id'] . " limit 1;"))) {
        $category = new ctrl_category($category['id']);
        echo "Updating existing category " . (!empty($row['category_name']) ? $row['category_name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New category on line $line was not inserted to database.\r\n";
          continue;
        }
        database::query("insert into " . DB_TABLE_CATEGORIES . " (id, date_created) values (" . (int)$row['id'] . ", '" . date('Y-m-d H:i:s') . "');");
        $category = new ctrl_category($row['category_id']);
        echo 'Creating new category: ' . $row['category_name'] . PHP_EOL;
      }
    } elseif (!empty($row['category_code'])) {
      // 如果包含了code数据，到insert_categories去查找该code对应的id。
      //同样的如果code在表里找不到，还是会根据insert_categories来决定是跳过当前数据还是穿件一个新的分类
      if ($category = database::fetch(database::query("select id from " . DB_TABLE_CATEGORIES . " where code = '" . database::input($row['category_code']) . "' limit 1;"))) {
        $category = new ctrl_category($category['category_id']);
        echo "Updating existing category " . (!empty($row['category_name']) ? $row['category_name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New category on line $line was not inserted to database.\r\n";
          continue;
        }
        $category = new ctrl_category();//创建新的分类
        echo 'Creating new category: ' . $row['category_name'] . PHP_EOL;
      }
    } elseif (!empty($row['category_name']) && !empty($row['category_language_code'])) {
      // 如果包含了name和language_code，到lc_categories_info里去查找对应的category_id，
      //如果找不到根据insert_categories决定是跳过当前行数据还是创建新数据
      if ($category = database::fetch(database::query("select category_id as id from " . DB_TABLE_CATEGORIES_INFO . " where name = '" . database::input($row['category_name']) . "' and language_code = '" . $row['category_language_code'] . "' limit 1;"))) {
        $category = new ctrl_category($category['category_id']);
        echo "Updating existing category " . (!empty($row['category_name']) ? $row['category_name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New category on line $line was not inserted to database.\r\n";
          continue;
        }
        $category = new ctrl_category();
      }
    } else {
      echo "[Skipped] Could not identify category on line $line.\r\n";
      continue;
    } // Find category  end
    // -------------------------------------------------------------------------------------
    //              导入数据时基本数据的判断以及创建$category到此结束，一下是设置$category 对象相关数据的代码。
    //---------------------------------------------------------------------------------------


    if (isset($row['category_dock'])) $row['category_dock'] = explode(',', $row['category_dock']);

    // Set default category data
    if (empty($category->data['id']) && empty($row['category_dock']) && empty($row['category_parent_id'])) {
      $category->data['dock'][] = 'tree';
    }

    // Set new category data
    foreach (array('category_parent_id', 'category_status', 'category_code', 'category_dock', 'category_keywords', 'category_image') as $field) {
      $sub_field = str_replace("category_","",$field);
      if (isset($row[$field])) $category->data[$sub_field] = $row[$field];
    }

    // Set category info data
    foreach (array('category_name', 'category_short_description', 'category_description', 'category_head_title', 'category_h1_title', 'category_meta_description') as $field) {
      $sub_field = str_replace("category_","",$field);
      if (isset($row[$field])) $category->data[$sub_field][$row['category_language_code']] = $row[$field];
    }

    if (isset($row['category_new_image'])) {//这里怎么可能会有new_image的数据呢？
      $category->save_image($row['category_new_image']);
    }

    $category->save();

    //更新数据
    if (!empty($row['category_date_created'])) {
      database::query(
        "update " . DB_TABLE_CATEGORIES . "
            set date_created = '" . date('Y-m-d H:i:s', strtotime($row['category_date_created'])) . "'
            where id = " . (int)$category->data['id'] . "
            limit 1;"
      );
    }
  }
}// import_categories end.

/**
 * 导入产品
 * @param $csv csv文件
 * @param $isInsertNew 当数据不存在时是否插入新数据。如果true，当数据库找不到导入的数据时，则将新增数据到数据库.
 */
function importProducts($csv,$isInsertNew)
{
  $line = 0;

  foreach ($csv as $row) {//import products start.
    $line++;
    /*------------------------------------------------------------------------------------
     * 逻辑梳理：导入导出产品涉及11张表
     * 1. 根据id到lc_products里查询数据，如果没有数据则根据insert_products来决定是否添加或跳过当前行的插入。
     * 2. 无论是找到或新增数据后，都要生成$product对象。
     * -----------------------------------------------------------------------------------
     */
    // Find product
    if (!empty($row['id'])) {
      if ($product = database::fetch(database::query("select id from " . DB_TABLE_PRODUCTS . " where id = " . (int)$row['id'] . " limit 1;"))) {
        $product = new ctrl_product($product['id']);
        echo "Updating existing product " . (!empty($row['name']) ? $row['name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New product on line $line was not inserted to database.\r\n";
          continue;
        }
        database::query("insert into " . DB_TABLE_PRODUCTS . " (id, date_created) values (" . (int)$row['id'] . ", '" . date('Y-m-d H:i:s') . "');");
        $product = new ctrl_product($row['id']);
        echo 'Creating new product: ' . $row['name'] . PHP_EOL;
      }

    } elseif (!empty($row['code'])) {
      if ($product = database::fetch(database::query("select id from " . DB_TABLE_PRODUCTS . " where code = '" . database::input($row['code']) . "' limit 1;"))) {
        $product = new ctrl_product($product['id']);
        echo "Updating existing product " . (!empty($row['name']) ? $row['name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New product on line $line was not inserted to database.\r\n";
          continue;
        }
        $product = new ctrl_product();
        echo 'Creating new product: ' . $row['name'] . PHP_EOL;
      }

    } elseif (!empty($row['sku'])) {
      if ($product = database::fetch(database::query("select id from " . DB_TABLE_PRODUCTS . " where sku = '" . database::input($row['sku']) . "' limit 1;"))) {
        $product = new ctrl_product($product['id']);
        echo "Updating existing product " . (!empty($row['name']) ? $row['name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New product on line $line was not inserted to database.\r\n";
          continue;
        }
        $product = new ctrl_product();
        echo 'Creating new product: ' . $row['name'] . PHP_EOL;
      }

    } elseif (!empty($row['mpn'])) {
      if ($product = database::fetch(database::query("select id from " . DB_TABLE_PRODUCTS . " where mpn = '" . database::input($row['mpn']) . "' limit 1;"))) {
        $product = new ctrl_product($product['id']);
        echo "Updating existing product " . (!empty($row['name']) ? $row['name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New product on line $line was not inserted to database.\r\n";
          continue;
        }
        $product = new ctrl_product();
        echo 'Creating new product: ' . $row['name'] . PHP_EOL;
      }

    } elseif (!empty($row['gtin'])) {
      if ($product = database::fetch(database::query("select id from " . DB_TABLE_PRODUCTS . " where gtin = '" . database::input($row['gtin']) . "' limit 1;"))) {
        $product = new ctrl_product($product['id']);
        echo "Updating existing product " . (!empty($row['name']) ? $row['name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New product on line $line was not inserted to database.\r\n";
          continue;
        }
        $product = new ctrl_product();
        echo 'Creating new product: ' . $row['name'] . PHP_EOL;
      }

    } elseif (!empty($row['name']) && !empty($row['language_code'])) {
      // 查找lc_product_info表
      if ($product = database::fetch(database::query("select product_id as id from " . DB_TABLE_PRODUCTS_INFO . " where name = '" . database::input($row['name']) . "' and language_code = '" . $row['language_code'] . "' limit 1;"))) {
        $product = new ctrl_product($product['id']);
        echo "Updating existing product " . (!empty($row['name']) ? $row['name'] : "on line $line") . "\r\n";
      } else {
        if ($isInsertNew == false) {
          echo "[Skipped] New product on line $line was not inserted to database.\r\n";
          continue;
        }
        $product = new ctrl_product();
      }

    } else {
      echo "[Skipped] Could not identify product on line $line.\r\n";
      continue;
    }// Find product end.

    //
    if (empty($row['manufacturer_id']) && !empty($row['manufacturer_name'])) {
      // find table manufacturs
      $manufacturers_query = database::query(
        "select * from " . DB_TABLE_MANUFACTURERS . "
            where name = '" . database::input($row['manufacturer_name']) . "'
            limit 1;"
      );
      if ($manufacturer = database::fetch($manufacturers_query)) {
        $row['manufacturer_id'] = $manufacturer['id'];
      } else {
        $manufacturer = new ctrl_manufacturer();
        $manufacturer->data['name'] = $row['manufacturer_name'];
        $manufacturer->save();
        $row['manufacturer_id'] = $manufacturer->data['id'];
      }
    }

    if (empty($row['supplier_id']) && !empty($row['supplier_id'])) {
      // find table suppliers
      $suppliers_query = database::query(
        "select * from " . DB_TABLE_SUPPLIERS . "
            where name = '" . database::input($row['supplier_name']) . "'
            limit 1;"
      );
      if ($supplier = database::fetch($suppliers_query)) {
        $row['supplier_id'] = $supplier['id'];
      } else {
        $supplier = new ctrl_supplier();
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
    if (isset($row['product_groups'])) $product->data['product_groups'] = preg_split('#, ?#', $row['product_groups']);

    // Set price
    if (!empty($row['currency_code'])) {
      if (isset($row['price'])) $product->data['prices'][$row['currency_code']] = $row['price'];
    }

    // Set product info data
    if (!empty($row['language_code'])) {
      foreach (array('name', 'short_description', 'description', 'attributes', 'head_title', 'meta_description') as $field) {
        if (isset($row[$field])) {
          $product->data[$field][$row['language_code']] = $row[$field];
        }
      }
    }

    // Set product images.
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

      $i = 0;
      foreach ($row['images'] as $image) {
        if (!in_array($image, $current_images)) {
          $product_images['new' . ++$i] = array('filename' => $image);
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
        "update " . DB_TABLE_PRODUCTS . "
            set date_created = '" . date('Y-m-d H:i:s', strtotime($row['date_created'])) . "'
            where id = " . (int)$product->data['id'] . "
            limit 1;"
      );
    }
  }
}

/**
 * 完整导入分类和产品
 */
function importCategoriesAndProducts()
{
  try {
    if (!isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
      throw new Exception(language::translate('error_must_select_file_to_upload', 'You must select a file to upload'));
    }

    ob_clean();

    header('Content-Type: text/plain; charset=' . language::$selected['charset']);

    echo "CSV Import\r\n"
      . "----------\r\n";

    $csv = file_get_contents($_FILES['file']['tmp_name']);

    $csv = functions::csv_decode($csv, $_POST['delimiter'], $_POST['enclosure'], $_POST['escapechar'], $_POST['charset']);
    $isInsertNew = !empty($_POST['insert_products']);
    importCategories($csv,$isInsertNew);
    //importProducts($csv,$isInsertNew);
    exit;
  } catch (Exception $e) {
    notices::add('errors', $e->getMessage());
  }
}

/**
 * 导出分类
 */
function exportCategories()
{
  if (isset($_POST['export_categories'])) {

    try {
      if (empty($_POST['language_code'])) throw new Exception(language::translate('error_must_select_a_language', 'You must select a language'));

      $csv = array();

      $categories_query = database::query("select id from " . DB_TABLE_CATEGORIES . " order by parent_id;");
      while ($category = database::fetch($categories_query)) {
        $category = new ref_category($category['id'], $_POST['language_code']);

        $csv[] = array(
          'id' => $category->id,
          'status' => $category->status,
          'parent_id' => $category->parent_id,
          'code' => $category->code,
          'name' => $category->name,
          'keywords' => implode(',', $product->keywords),
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
        header('Content-Type: text/plain; charset=' . $_POST['charset']);
      } else {
        header('Content-Type: application/csv; charset=' . $_POST['charset']);
        header('Content-Disposition: attachment; filename=categories-' . $_POST['language_code'] . '.csv');
      }

      switch ($_POST['eol']) {
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

}

/*
 * 导出产品
 */
function exportProducts()
{
  //export_products start
  if (isset($_POST['export_products'])) {

    try {

      if (empty($_POST['language_code'])) throw new Exception(language::translate('error_must_select_a_language', 'You must select a language'));

      $csv = array();
      // select p.id from `litecart`.`lc_products` p left join `litecart`.`lc_products_info` pi on (pi.product_id = p.id and pi.language_code = 'en') order by pi.name;
      $query_sql = "select p.id from " . DB_TABLE_PRODUCTS . " p left join "
        . DB_TABLE_PRODUCTS_INFO
        . " pi on (pi.product_id = p.id and pi.language_code = '"
        . database::input($_POST['language_code']) . "') order by pi.name;";
      $products_query = database::query($query_sql);

      while ($product = database::fetch($products_query)) {
        $product = new ref_product($product['id'], $_POST['language_code'], $_POST['currency_code']);

        $csv[] = array(
          'id' => $product->id,
          'status' => $product->status,
          'categories' => implode(',', array_keys($product->categories)),
          'product_groups' => implode(',', array_keys($product->product_groups)),
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
          'attributes' => $product->attributes,
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
        header('Content-Type: text/plain; charset=' . $_POST['charset']);
      } else {
        header('Content-Type: application/csv; charset=' . $_POST['charset']);
        header('Content-Disposition: attachment; filename=products-' . $_POST['language_code'] . '.csv');
      }

      switch ($_POST['eol']) {
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
  }//export_products end.

}

/**
 * 完整导出分类和产品
 */
function exportCategoriesAndProducts()
{
  exportCategories();
  exportProducts();
}

// import or export run.
if (isset($_POST['import_products'])) {
  importCategoriesAndProducts();
} elseif (isset($_POST['export_products'])) {
  exportCategoriesAndProducts();
}
?>
<h1><?php echo $app_icon; ?><?php echo language::translate('title_csv_import_export', 'CSV Import/Export'); ?></h1>
<!-- import or export csv html content 2018 17:07 zn add annotation in here -->
<div class="row">
    <!--import products -->
    <div class="col-md-6">
        <h2><?php echo language::translate('title_products', 'Products'); ?></h2>
        <div class="row">
            <div class="col-md-6">
                <fieldset class="well">
                    <legend><?php echo language::translate('title_import_from_csv', 'Import From CSV'); ?></legend>
                  <?php echo functions::form_draw_form_begin('import_products_form', 'post', '', true); ?>

                    <div class="form-group">
                        <label><?php echo language::translate('title_csv_file', 'CSV File'); ?></label>
                      <?php echo functions::form_draw_file_field('file'); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_delimiter', 'Delimiter'); ?></label>
                      <?php echo functions::form_draw_select_field('delimiter', array(array(language::translate('title_auto', 'Auto') . ' (' . language::translate('text_default', 'default') . ')', ''), array(','), array(';'), array('TAB', "\t"), array('|')), true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_enclosure', 'Enclosure'); ?></label>
                      <?php echo functions::form_draw_select_field('enclosure', array(array('" (' . language::translate('text_default', 'default') . ')', '"')), true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_escape_character', 'Escape Character'); ?></label>
                      <?php echo functions::form_draw_select_field('escapechar', array(array('" (' . language::translate('text_default', 'default') . ')', '"'), array('\\', '\\')), true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_charset', 'Charset'); ?></label>
                      <?php echo functions::form_draw_encodings_list('charset', !empty($_POST['charset']) ? true : 'UTF-8', false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo functions::form_draw_checkbox('insert_products', 'true', true); ?><?php echo language::translate('text_insert_new_products', 'Insert new products'); ?></label>
                    </div>

                  <?php echo functions::form_draw_button('import_products', language::translate('title_import', 'Import'), 'submit'); ?>

                  <?php echo functions::form_draw_form_end(); ?>
                </fieldset>
            </div><!-- import products end-->
            <!--export products -->
            <div class="col-md-6">
                <fieldset class="well">
                    <legend><?php echo language::translate('title_export_to_csv', 'Export To CSV'); ?></legend>

                  <?php echo functions::form_draw_form_begin('export_products_form', 'post'); ?>

                    <div class="form-group">
                        <label><?php echo language::translate('title_language', 'Language'); ?></label>
                      <?php echo functions::form_draw_languages_list('language_code', true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_currency', 'Currency'); ?></label>
                      <?php echo functions::form_draw_currencies_list('currency_code', true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_delimiter', 'Delimiter'); ?></label>
                      <?php echo functions::form_draw_select_field('delimiter', array(array(', (' . language::translate('text_default', 'default') . ')', ','), array(';'), array('TAB', "\t"), array('|')), true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_enclosure', 'Enclosure'); ?></label>
                      <?php echo functions::form_draw_select_field('enclosure', array(array('" (' . language::translate('text_default', 'default') . ')', '"')), true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_escape_character', 'Escape Character'); ?></label>
                      <?php echo functions::form_draw_select_field('escapechar', array(array('" (' . language::translate('text_default', 'default') . ')', '"'), array('\\', '\\')), true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_charset', 'Charset'); ?></label>
                      <?php echo functions::form_draw_encodings_list('charset', !empty($_POST['charset']) ? true : 'UTF-8', false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_line_ending', 'Line Ending'); ?></label>
                      <?php echo functions::form_draw_select_field('eol', array(array('Win'), array('Mac'), array('Linux')), true, false); ?>
                    </div>

                    <div class="form-group">
                        <label><?php echo language::translate('title_output', 'Output'); ?></label>
                      <?php echo functions::form_draw_select_field('output', array(array(language::translate('title_file', 'File'), 'file'), array(language::translate('title_screen', 'Screen'), 'screen')), true, false); ?>
                    </div>

                  <?php echo functions::form_draw_button('export_products', language::translate('title_export', 'Export'), 'submit'); ?>

                  <?php echo functions::form_draw_form_end(); ?>
                </fieldset>
            </div><!--export products end-->
        </div>
    </div>
</div>
