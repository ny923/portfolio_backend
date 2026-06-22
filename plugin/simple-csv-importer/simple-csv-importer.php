<?php

/**
 * Plugin Name: Simple Home's CSV Importer
 * Description: Pythonから出力されたclean_homes.csvを読み込み、物件として登録する専用プラグイン
 * Version: 1.1
 * Author: with AI
 */

if (! defined('ABSPATH')) exit;

// 設定：読み込むCSVのパス（Pythonが出力する場所と合わせる）
define('SCI_CSV_PATH', WP_CONTENT_DIR . '/uploads/wpallimport/files/homes.csv');
define('SCI_ACCESS_KEY', 'stitch-3315');

// 1. Webフック（受取口）の作成
add_action('rest_api_init', function () {
    register_rest_route('simple-importer/v1', '/run', array(
        'methods' => 'GET',
        'callback' => 'sci_run_import',
        'permission_callback' => '__return_true', // キー認証を内部で行うためここはオープン
    ));
});


// ｽﾗｯｼｭ処理 共通 設備、リフォーム箇所など
function convertCodesToNames($raw_string, $map_array)
{
    if (empty(trim($raw_string))) {
        return '';
    }

    $list = [];
    $codes = explode('/', $raw_string);
    foreach ($codes as $code) {
        $trimmed_code = trim($code);
        if (isset($map_array[$trimmed_code])) {
            $list[] = $map_array[$trimmed_code];
        }
    }
    return implode(' / ', $list);
}

// 日付変換 共通
function formatJapaneseDate($value)
{
    $trimmed = trim($value);
    if (empty($trimmed)) return $trimmed;
    $date_obj = DateTime::createFromFormat('M-y', $trimmed);
    return $date_obj ? $date_obj->format('Y年n月') : $trimmed;
}

// 2. インポート実行関数
function sci_run_import($request)
{
    // 認証チェック
    $params = $request->get_params();
    if (! isset($params['key']) || $params['key'] !== SCI_ACCESS_KEY) {
        return new WP_Error('forbidden', 'Invalid Access Key', array('status' => 403));
    }

    // --- 路線・駅マスタの読み込みとインデックス化 ---
    $line_master = [];
    $station_master = [];
    $master_path = plugin_dir_path(__FILE__) . 'train_master.csv';

    if (file_exists($master_path)) {
        $m_handle = fopen($master_path, 'r');
        while (($m_row = fgetcsv($m_handle, 0, ",")) !== FALSE) {
            // m_row[0]:路線ID, m_row[1]:路線名, m_row[3]:駅ID, m_row[4]:駅名
            $line_master[$m_row[0]] = $m_row[1];
            $station_master[$m_row[3]] = $m_row[4];
        }
        fclose($m_handle);
    }

    // CSVファイルの存在確認
    if (! file_exists(SCI_CSV_PATH)) {
        return new WP_Error('not_found', 'CSV file not found at: ' . SCI_CSV_PATH, array('status' => 404));
    }

    // タイムアウト対策（大量データ用）
    set_time_limit(0);

    // CSV読み込み開始
    $handle = fopen(SCI_CSV_PATH, 'r');
    if (! $handle) {
        return new WP_Error('file_error', 'Could not open CSV', array('status' => 500));
    }

    // 変換マップ外部ファイルから読む 物件種別 設備のみ
    $mapping_data = include plugin_dir_path(__FILE__) . 'mapping-data.php';
    $type_map = $mapping_data['type_map'];
    $map_flag = $mapping_data['map_flag'];
    $map_condition = $mapping_data['map_condition'];
    $map_public_name = $mapping_data['map_public_name'];
    $map_measure_method = $mapping_data['map_measure_method'];
    $map_tax = $mapping_data['map_tax'];
    $map_transact_type = $mapping_data['map_transact_type'];
    $map_direction = $mapping_data['map_direction'];
    $daylight_direction = $mapping_data['map_direction'];
    $location_design = $mapping_data['location_design'];
    $map_type = $mapping_data['map_type'];
    $map_notification = $mapping_data['map_notification'];
    $equipment_map = $mapping_data['map_equipment'];
    $map_land_use = $mapping_data['map_land_use'];
    $map_zoning = $mapping_data['map_zoning'];
    $map_access_road = $mapping_data['map_access_road'];
    $rights_map = $mapping_data['rights_map'];
    $map_structure = $mapping_data['map_structure'];
    $floor_type_map = $mapping_data['floor_type_map'];
    $map_floor_detail = $mapping_data['map_floor_detail'];
    $map_situation_land = $mapping_data['map_situation_land'];
    $map_situation_building = $mapping_data['map_situation_building'];
    $map_parking = $mapping_data['map_parking'];
    $map_situation_parking = $mapping_data['map_situation_parking'];
    $map_delivery = $mapping_data['map_delivery'];
    $map_urban_plan = $mapping_data['map_urban_plan'];
    $map_topography = $mapping_data['map_topography'];
    $map_setback = $mapping_data['map_setback'];
    $map_building_measure = $mapping_data['map_building_measure'];
    $map_reform_water = $mapping_data['map_reform_water'];
    $map_reform_interior = $mapping_data['map_reform_interior'];
    $map_reform_exterior = $mapping_data['map_reform_exterior'];
    $map_working = $mapping_data['map_working'];
    $map_management = $mapping_data['map_management'];

    // --- 1. カテゴリのグループ化設定（紐付けルール） ---
    $category_groups = [
        '新築戸建' => ['新築戸建',  '新築テラスハウス'],
        '中古戸建' => ['中古戸建',  '中古テラスハウス'],
        '土地' => ['売地', '借地権譲渡', '底地権譲渡'],
        'マンション' => ['新築マン', '中古マン', '新築公団', '中古公団', '新築公社', '中古公社', '新築タウン', '中古タウン', 'リゾートマン'],
    ];

    $count_new = 0;
    $count_updated = 0;
    $count_withdrawn = 0;
    $processed_property_ids = []; // ★今回のCSVに存在するIDを記録する配列

    // --- 2. インポート実行関数 内の ループ ---
    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        // array_popを使わず、末尾から逆算して取得する（元の配列を壊さない）
        $last_index     = count($data) - 1;
        $wpm_block_flag = trim($data[$last_index] ?? 'yes');
        $spec_html      = $data[$last_index - 1] ?? '';

        $property_id = $data[0];
        if (empty($property_id) || $property_id === '自社管理物件番号') continue;

        // 処理済みリストに追加
        $processed_property_ids[] = $property_id;

        // ログに出力して、どこで止まったか確認
        error_log("インポート処理中: 物件ID " . $property_id);

        $update_date = $data[1]; // 情報更新日 2
        $expiration_date =  $data[2]; // 掲載期限 3
        $raw_type = trim($data[6]); // 物件種別の変換 7
        $property_type = isset($type_map[$raw_type]) ? $type_map[$raw_type] : $raw_type;
        $property_name = $data[9]; // 物件名 10
        $vacant_num = $data[13]; // 空き物件数14

        $address = $data[17]; // 所在地 18

        // 検索に利用 エリア（市区町村）の自動抽出と紐付け
        $address_full = trim($data[17]); // 所在地名称 (18列目)
        // 正規表現で「群馬県」の後の「○○市」または「○○郡○○町/村」を抽出
        // 群馬県以外にも対応できるよう、県名は任意(省略可)にしています
        $city_name = '';
        if (preg_match('/(?:群馬県)?(.+?[市区町村])/u', $address_full, $matches)) {
            $city_name = $matches[1]; // 例：「前橋市」「高崎市」「北群馬郡吉岡町」などが入る
        }
        $location = $data[20]; //緯度経度 21

        // 交通22~32 マッピング
        $r1_id = trim($data[21]); // 路線1 ID
        $s1_id = trim($data[22]); // 駅1 ID
        $r2_id = trim($data[26]); // 路線2 ID
        $s2_id = trim($data[27]); // 駅2 ID

        $line1_name    = $line_master[$r1_id] ?? $r1_id;
        $station1_name = $station_master[$s1_id] ?? $s1_id;
        $line2_name    = $line_master[$r2_id] ?? $r2_id;
        $station2_name = $station_master[$s2_id] ?? $s2_id;

        // $traffic 交通22~32
        $traffic = sprintf(
            '%s　%s駅／バス停 %s　バス時間 %s／徒歩距離 %s' . 'm' . '／%s　%s駅／バス停 %s　バス時間 %s　徒歩距離 %s' . 'm' . '／その他 %s',
            $line1_name,
            $station1_name,
            $data[23],
            $data[24],
            $data[25],
            $line2_name,
            $station2_name,
            $data[28],
            $data[29],
            $data[30],
            $data[31]
        );

        // list(セル)用traffic
        $list_traffic = sprintf(
            '%s　%s駅',
            $line1_name,
            $station1_name
        );

        $timoku = trim($data[33]); // 地目 34
        $land_use = isset($map_land_use[$timoku]) ? $map_land_use[$timoku] : $timoku;
        $youto = trim($data[34]); // 用途地域 35
        $urban_plan = $data[35]; // 都市計画36
        $planning = isset($map_urban_plan[$urban_plan]) ? $map_urban_plan[$urban_plan] : $urban_plan;
        $zoning = isset($map_zoning[$youto]) ? $map_zoning[$youto] : $youto;
        $land_area     = trim($data[38]); // 区画面積 39
        $coverage = $data[44]; // 建ぺい率45
        $floor_area_ratio = $data[45]; // 容積率46
        $setudo = trim($data[46]); // 接道状況 47
        $access_road = isset($map_access_road[$setudo]) ? $map_access_road[$setudo] : $setudo;
        $raw_rights = trim($data[67]); // 土地権利 68
        $rights = isset($rights_map[$raw_rights]) ? $rights_map[$raw_rights] : $raw_rights;
        $kozo = trim($data[70]); // 構造71
        $structure = isset($map_structure[$kozo]) ? $map_structure[$kozo] : $kozo;
        $building_area = trim($data[72]); // 建物・専有面積 73

        // 日付の表記変換※共通関数
        $property_age         = formatJapaneseDate($data[78]);
        $reform_done_water    = formatJapaneseDate($data[400]);
        $reform_done_exterior = formatJapaneseDate($data[406]);
        $room_count = trim($data[87]); // 間取り 部屋数 88
        $raw_floor_type = trim($data[88]); // タイプ 89
        // タイプを文字に変換（50 → LDK
        $floor_str = isset($floor_type_map[$raw_floor_type]) ? $floor_type_map[$raw_floor_type] : $raw_floor_type;
        $floor = $room_count . $floor_str;

        // 間取り詳細 90~130
        // 共通変換必要 90 94 98 102 106 110 114 118 122 126
        $room_type90 = isset($map_floor_detail[trim($data[89])]) ? $map_floor_detail[trim($data[89])] : trim($data[89]);
        $room_type94 = isset($map_floor_detail[trim($data[93])]) ? $map_floor_detail[trim($data[93])] : trim($data[93]);
        $room_type98 = isset($map_floor_detail[trim($data[97])]) ? $map_floor_detail[trim($data[97])] : trim($data[97]);
        $room_type102 = isset($map_floor_detail[trim($data[101])]) ? $map_floor_detail[trim($data[101])] : trim($data[101]);
        $room_type106 = isset($map_floor_detail[trim($data[105])]) ? $map_floor_detail[trim($data[105])] : trim($data[105]);
        $room_type110 = isset($map_floor_detail[trim($data[109])]) ? $map_floor_detail[trim($data[109])] : trim($data[109]);
        $room_type114 = isset($map_floor_detail[trim($data[113])]) ? $map_floor_detail[trim($data[113])] : trim($data[113]);
        $room_type118 = isset($map_floor_detail[trim($data[117])]) ? $map_floor_detail[trim($data[117])] : trim($data[117]);
        $room_type122 = isset($map_floor_detail[trim($data[121])]) ? $map_floor_detail[trim($data[121])] : trim($data[121]);
        $room_type126 = isset($map_floor_detail[trim($data[125])]) ? $map_floor_detail[trim($data[125])] : trim($data[125]);
        $floor_detail = $room_type90 . $data[90] . $data[91] . $data[92] . $room_type94 . $data[94] . $data[95] . $data[96] . $room_type98 . $data[98] . $data[99] . $data[100] . $room_type102 . $data[102] . $data[103] . $data[104] . $room_type106 . $data[106] . $data[107] . $data[108] . $room_type110 . $data[110] . $data[111] . $data[112] . $room_type114 . $data[114] . $data[115] . $data[116] . $room_type118 . $data[118] . $data[119] . $data[120] . $room_type122 . $data[122] . $data[123] . $data[124] . $room_type126 . $data[126] . $data[127] . $data[128] . $data[129];
        $feature = $data[130]; // 特徴 131
        $remark  = $data[133]; // 備考 134
        $price   = trim($data[138]); // 価格 139
        // 駐車場料金178 駐車場区分180 駐車場距離181 駐車場空き台数182 駐車場備考183
        // $parking = '料金' . $data[177] . '　区分' . $data[179] . '　距離' . $data[180] . '　空き台数' . $data[181] . '　備考' . $data[182];
        $parking_type = trim($data[179]); //有 ｶｰｽﾍﾟｰｽ 位にする
        $parking = isset($map_parking[$parking_type]) ? $map_parking[$parking_type] : $parking_type . '　' . $data[182];

        // --- 1. まず変数を空で初期化（エラー防止） ---
        $situation_land = $situation_building = $situation_parking = '';
        $raw_type_int = (int)$raw_type; // 比較しやすいように数値（整数）に変換

        // --- 2. 条件分岐 ---
        // 土地の場合 (1101, 1102, 1103)
        if (in_array($raw_type_int, [1101, 1102, 1103])) {
            $toti = trim($data[183]);
            $situation_land = $map_situation_land[$toti] ?? $toti;
        }
        // 建物（戸建・マン等）の場合 (範囲指定)
        elseif (
            ($raw_type_int >= 1201 && $raw_type_int <= 1204) ||
            ($raw_type_int >= 1301 && $raw_type_int <= 1309) ||
            ($raw_type_int >= 1401 && $raw_type_int <= 1499) ||
            ($raw_type_int >= 1502 && $raw_type_int <= 1599)
        ) {
            $build = trim($data[183]);
            $situation_building = $map_situation_building[$build] ?? $build;
        }
        // 駐車場の場合 (3282)
        elseif ($raw_type_int === 3282) {
            $park = trim($data[183]);
            $situation_parking = $map_situation_parking[$park] ?? $park;
        }
        // 分岐ここまで

        $hikiwatasi = $data[184]; // 引渡185
        $delivery = isset($map_delivery[$hikiwatasi]) ? $map_delivery[$hikiwatasi] : $hikiwatasi;

        $primary_school = $data[187]; // 小学校区188
        $ps_distance = $data[188]; //小学校距離 189
        $junior_high = $data[190]; // 中学校区191
        $jh_distance = $data[191]; // 中学校距離 192
        $conveni = $data[193]; // コンビニ距離 194
        $super = $data[194]; // スーパー距離 195
        $hospital = $data[195]; // 総合病院距離 196

        // ｽﾗｯｼｭ処理必要な項目
        $equipment    = convertCodesToNames($data[249], $equipment_map);
        $reform_water = convertCodesToNames($data[398], $map_reform_water);
        $reform_interior = convertCodesToNames($data[401], $map_reform_interior);
        $reform_exterior = convertCodesToNames($data[404], $map_reform_exterior);

        $own_flag = $data[4];
        $flag = isset($map_flag[$own_flag]) ? $map_flag[$own_flag] : $own_flag;
        $jotai = $data[5];
        $condition = isset($map_condition[$jotai]) ? $map_condition[$jotai] : $jotai;
        $ruby = $data[10];
        $name_kokai = trim($data[11]);
        $public_name = isset($map_public_name[$name_kokai]) ? $map_public_name[$name_kokai] : $name_kokai;
        $total_unit_plot = $data[12];
        $zip = $data[15];
        $location_code = $data[16];
        $location_detail_open = $data[18];
        $location_detail_hide = $data[19];
        $tisei = $data[36];
        $topography = isset($map_topography[$tisei]) ? $map_topography[$tisei] : $tisei;
        $measure_method = trim($data[37]);
        $land_measure_method = isset($map_measure_method[$measure_method]) ? $map_measure_method[$measure_method] : $measure_method;
        $plot_area = $data[38];
        $burden_area = $data[39];
        $share_burden = $data[40];
        $ownership = $data[41];
        $zasetsu = $data[42];
        $setback = isset($map_setback[$zasetsu]) ? $map_setback[$zasetsu] : $zasetsu;
        $setback_amount = $data[43];
        $direction1 = trim($data[47]);
        $access_direction1 = isset($map_direction[$direction1]) ? $map_direction[$direction1] : $direction1;
        $access_frontage1 = $data[48];
        $type1 = trim($data[49]);
        $access_type1 = isset($map_type[$type1]) ? $map_type[$type1] : $type1;
        $access_width1 = $data[50];
        $map_private_road1 = trim($data[51]);
        $private_road1 = isset($location_design[$map_private_road1]) ? $location_design[$map_private_road1] : $map_private_road1;
        $direction2 = trim($data[52]);
        $access_direction2 = isset($map_direction[$direction2]) ? $map_direction[$direction2] : $direction2;
        $access_frontage2 = trim($data[53]);
        $type2 = $data[54];
        $access_type2 = isset($map_type[$type2]) ? $map_type[$type2] : $type2;
        $access_width2 = $data[55];
        $map_private_road2 = trim($data[56]);
        $private_road2 = isset($location_design[$map_private_road2]) ? $location_design[$map_private_road2] : $map_private_road2;
        $direction3 = trim($data[57]);
        $access_direction3 = isset($map_direction[$direction3]) ? $map_direction[$direction3] : $direction3;
        $access_frontage3 = $data[58];
        $type3 = trim($data[59]);
        $access_type3 = isset($map_type[$type3]) ? $map_type[$type3] : $type3;
        $access_width3 = $data[60];
        $map_private_road3 = trim($data[61]);
        $private_road3 = isset($location_design[$map_private_road3]) ? $location_design[$map_private_road3] : $map_private_road3;
        $direction4 = trim($data[62]);
        $access_direction4 = isset($map_direction[$direction4]) ? $map_direction[$direction4] : $direction4;
        $access_frontage4 = $data[63];
        $type4 = trim($data[64]);
        $access_type4 = isset($map_type[$type4]) ? $map_type[$type4] : $type4;
        $access_width4 = $data[65];
        $map_private_road4 = trim($data[66]);
        $private_road4 = isset($location_design[$map_private_road4]) ? $location_design[$map_private_road4] : $map_private_road4;
        $todokede = trim($data[68]);
        $notification = isset($map_notification[$todokede]) ? $map_notification[$todokede] : $todokede;
        $restriction = $data[69];
        $building_measure = $data[71];
        $building_measure_method = isset($map_building_measure[$building_measure]) ? $map_building_measure[$building_measure] : $building_measure;
        $site_area = $data[73];
        $floor_area = $data[74];
        $architect_area = $data[75];
        $above_floor = $data[76];
        $under_floor = $data[77];
        $new_flag = $data[79];

        // 勤務形態 administrator
        $administrator_map = trim($data[80]);
        $administrator = isset($map_working[$administrator_map]) ? $map_working[$administrator_map] : $administrator_map;

        // 管理形態 manage_form
        $manage_form_map = trim($data[81]);
        $manage_form = isset($map_management[$manage_form_map]) ? $map_management[$manage_form_map] : $manage_form_map;
        $manage_union = $data[82];
        $manage_name = $data[83];
        $floor_num = $data[84];
        $balcony_area = $data[85];

        // 部屋向き
        $room_daylight_direction = trim($data[86]);
        $daylight_direction = isset($map_direction[$room_daylight_direction]) ? $map_direction[$room_daylight_direction] : $room_daylight_direction;

        $url = $data[136];
        $public_flag = $data[139];
        $price_state = $data[140];
        $zei = trim($data[141]);
        $tax = isset($map_tax[$zei]) ? $map_tax[$zei] : $zei;
        $tax_amount = $data[142];
        $unit_price = $data[143];
        $common_manage_fee = $data[144];
        $fee_zei = trim($data[145]);
        $fee_tax = isset($map_tax[$fee_zei]) ? $map_tax[$fee_zei] : $fee_zei;
        $repair_reserve = $data[165];
        $repair_fund = $data[166];
        $expense_name1 = $data[167];
        $expense_cost1 = $data[168];
        $expense_name2 = $data[169];
        $expense_cost2 = $data[170];
        $expense_name3 = $data[171];
        $expense_cost3 = $data[172];
        $transact = trim($data[197]);
        $transact_type = isset($map_transact_type[$transact]) ? $map_transact_type[$transact] : $transact;
        $affiliate_group = $data[248];
        $recommend_num = $data[250];
        $restrict_note = $data[251];
        $building_note = $data[252];
        $construct_name = $data[253];
        $confirm_num = $data[254];
        $renovation_date = $data[270];
        $renovation_detail = $data[271];
        $structure_etc = $data[369];
        $comment_temp_num = $data[391];
        $panorama_id = $data[392];
        $panorama_flag = $data[393];
        $panorama_del_flag = $data[394];
        $staff_comment_type = $data[395];
        $staff_comment = $data[396];
        $reform_etc_water = $data[399];
        $reform_etc_interior = $data[402];
        $reform_done_interior = $data[403];
        $reform_etc_exterior = $data[405];
        $reform_share = $data[407];
        $reform_done_share = $data[408];
        $reform_remark = $data[409];

        // --- WordPress 登録セクション ---
        $post_data = array(
            'post_title'   => '物件 ' . $property_id, // タイトル生成ルールがあれば変更
            'post_content' => $spec_html,           // 本文にスペック表を入れる
            'post_type'    => 'property',           // ★カスタム投稿タイプ名
            'post_status'  => 'publish', // 再掲載時も考慮して常にpublishで上書き
        );

        $existing_post = get_posts([
            'meta_key' => 'property_id',
            'meta_value' => $property_id,
            'post_type' => 'property',
            'post_status' => 'any',
            'numberposts' => 1
        ]);

        if ($existing_post) {
            $post_id = $existing_post[0]->ID;
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
            $count_updated++;
        } else {
            $post_id = wp_insert_post($post_data);
            update_post_meta($post_id, 'property_id', $property_id);
            $count_new++;
        }

        if (!$post_id) continue;

        if (!empty($city_name)) {
            // 今回は「area」というタクソノミー（なければ作成が必要）に保存する例
            // もし既存の「category」に入れたい場合は 'category' に変更してください
            $area_taxonomy = 'area';

            // タクソノミーが存在するか確認し、なければ作成（念のため）
            if (!taxonomy_exists($area_taxonomy)) {
                register_taxonomy($area_taxonomy, 'property', ['label' => 'エリア', 'hierarchical' => true]);
            }

            // ターム（前橋市など）が存在するか確認し、なければ作成
            $term = term_exists($city_name, $area_taxonomy);
            if (!$term) {
                $term = wp_insert_term($city_name, $area_taxonomy);
            }

            // 物件（投稿）にエリアを紐付け（falseで既存の紐付けを上書きしない設定）
            if (!is_wp_error($term)) {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                wp_set_object_terms($post_id, (int)$term_id, $area_taxonomy, true);
            }
        } // 所在地抽出ここまで


        // 3. カテゴリ（タクソノミー）の自動紐付け
        $taxonomy = 'category';
        $category_slugs = [
            '土地'       => 'land',
            'マンション' => 'mansion',
            '新築戸建'   => 'new-house',
            '中古戸建'   => 'used-house',
            '一般公開物件' => 'public-property', // ★これを追加
            '会員限定物件' => 'members-property',      // ★ついでにこちらも推奨
        ];

        // 割り当てるカテゴリー名の配列を作成
        $target_category_names = [$property_type];
        foreach ($category_groups as $group_name => $types) {
            if (in_array($property_type, $types)) {
                $target_category_names[] = $group_name;
            }
        }

        // Pythonから届いたフラグを確認（デバッグ用：wp-content/debug.logに出力されます）
        $wpm_block_flag = trim($wpm_block_flag);


        // プラグインWP-Membersとの施/開錠同期
        // $is_public_status は冒頭で取得した変数で判定
        $is_public_status = (strcasecmp($wpm_block_flag, 'no') === 0);

        if ($is_public_status) {
            // 【開錠処理】
            // 1. カテゴリを一般公開に設定
            $target_category_names[] = '一般公開物件';

            // 2. 閲覧制限を明示的に「解除(0)」に設定（deleteではなくupdateを使用）
            update_post_meta($post_id, '_wpmembers_block', '0');
        } else {
            // 【施錠処理】
            // 1. カテゴリを会員限定に設定
            $target_category_names[] = '会員限定物件';

            // 2. 閲覧制限を「制限(1)」に設定
            update_post_meta($post_id, '_wpmembers_block', '1');
        }


        // 文字列の名前をタームIDに変換しつつ、スラッグを強制する処理
        $term_ids = [];

        foreach ($target_category_names as $term_name) {
            if (empty($term_name)) continue;

            $target_slug = isset($category_slugs[$term_name]) ? $category_slugs[$term_name] : '';
            $term = get_term_by('name', $term_name, $taxonomy);

            if (!$term) {
                // 新規作成時にスラッグを指定
                $new_term = wp_insert_term($term_name, $taxonomy, ['slug' => $target_slug]);
                if (!is_wp_error($new_term)) {
                    $term_ids[] = (int)$new_term['term_id'];
                }
            } else {
                // 既存のタームのスラッグが違えば「land」などに更新
                if ($target_slug && $term->slug !== $target_slug) {
                    wp_update_term($term->term_id, $taxonomy, ['slug' => $target_slug]);
                }
                $term_ids[] = (int)$term->term_id;
            }
        }

        // ID配列で一括紐付け
        wp_set_object_terms($post_id, $term_ids, $taxonomy, false);

        // --- 画像処理セクション ---
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // ★修正：既存のギャラリー画像を取得しておく（消さないためのベース）
        $gallery_images = get_post_meta($post_id, '_property_images', true) ?: [];

        // 重複チェック用に現在のIDリストを作成
        $existing_ids = array_column($gallery_images, 'id');

        $madori_attach_id = get_post_meta($post_id, 'madori', true); // 既存の間取りIDも取得

        // 画像が格納されている列のセットを定義 225-248 273-368
        $img_column_ranges = [
            ['start' => 224, 'end' => 247],
            ['start' => 272, 'end' => 367]
        ];

        foreach ($img_column_ranges as $range) {
            for ($i = $range['start']; $i <= $range['end']; $i += 4) {
                $img_file = trim($data[$i]) ?? '';      // ファイル名
                $img_update_date = trim($data[$i + 1] ?? ''); // いえらぶ側の更新日時
                $img_type = trim($data[$i + 2] ?? '');  // 種別（今回は保存しないが判定に利用可能）
                $img_comm = trim($data[$i + 3] ?? '');  // コメント

                if (empty($img_file)) continue;

                // 1. まず、そのファイル名で既にメディアライブラリに登録されているか確認
                $attach_id = null;
                $existing_attachments = get_posts([
                    'post_type'      => 'attachment',
                    'meta_key'       => '_wp_attached_file',
                    'meta_value'     => $img_file,
                    'posts_per_page' => 1,
                    'post_status'    => 'inherit',
                    'fields'         => 'ids'
                ]);

                if (!empty($existing_attachments)) {
                    $attach_id = $existing_attachments[0];
                }

                // 画像のフルパス
                $source_path = WP_CONTENT_DIR . '/uploads/wpallimport/files/' . $img_file;

                // 3. 画像の登録・更新処理
                if (file_exists($source_path)) {
                    // 新しいファイルが届いている場合
                    if ($attach_id) {
                        $stored_update_date = get_post_meta($attach_id, '_ierabu_last_update', true);
                        // 日付が違う場合のみ差し替え
                        if ($img_update_date !== $stored_update_date) {
                            $old_attach_id = $attach_id;
                            $attach_id = null; // 新規登録へ
                        }
                    }

                    if (!$attach_id) {
                        $file_array = ['name' => $img_file, 'tmp_name' => $source_path];
                        $new_id = media_handle_sideload($file_array, $post_id);
                        if (!is_wp_error($new_id)) {
                            $attach_id = $new_id;
                            update_post_meta($attach_id, '_ierabu_last_update', $img_update_date);
                            if (isset($old_attach_id)) wp_delete_attachment($old_attach_id, true);
                        }
                    }
                }

                // 4. ギャラリー配列への追加（attach_idがある場合）
                if ($attach_id && !is_wp_error($attach_id)) {
                    update_post_meta($attach_id, '_wp_attachment_image_alt', $img_comm);

                    if ($i === 224) set_post_thumbnail($post_id, $attach_id);
                    if ($i === 228) $madori_attach_id = $attach_id;

                    // ★修正：重複して追加されないようにチェック
                    $already_in_gallery = false;
                    foreach ($gallery_images as $idx => $item) {
                        if ($item['id'] == $attach_id) {
                            // 既存ならコメント等を更新
                            $gallery_images[$idx]['comment'] = $img_comm;
                            $already_in_gallery = true;
                            break;
                        }
                    }

                    if (!$already_in_gallery) {
                        $gallery_images[] = [
                            'id'      => $attach_id,
                            'comment' => $img_comm,
                            'type'    => $img_type
                        ];
                    }
                }
            }
        }

        // 4. カスタムフィールド保存
        $fields = [
            'madori'      => $madori_attach_id,
            'property_id' => $property_id,
            'update_date' => $update_date,
            'expiration_date' => $expiration_date,
            'vacant_num' => $vacant_num,
            'property_type' => $property_type,
            'property_name' => $property_name,
            'traffic' => $traffic,
            'list_traffic' => $list_traffic, //list用
            'address' => $address,
            'land_area' => $land_area,
            'building_area' => $building_area,
            'property_age' => $property_age,
            'floor' => $floor,
            'floor_detail' => $floor_detail,
            'feature' => $feature,
            'remark' => $remark,
            'price' => $price,
            'land_use' => $land_use,
            'zoning' => $zoning,
            'coverage' => $coverage,
            'floor_area_ratio' => $floor_area_ratio,
            'access_road' => $access_road,
            'rights' => $rights,
            'structure' => $structure,
            'parking' => $parking,
            'situation_land' => $situation_land,
            'situation_building' => $situation_building,
            'situation_parking' => $situation_parking,
            'delivery' => $delivery,
            'primary_school' => $primary_school,
            'ps_distance' => $ps_distance,
            'junior_high' => $junior_high,
            'jh_distance' => $jh_distance,
            'conveni' => $conveni,
            'super' => $super,
            'hospital' => $hospital,
            'equipment' => $equipment,
            'location' => $location,
            'planning' => $planning,
            'confirm_num' => $confirm_num,
            'flag' => $flag,
            'condition' => $condition,
            'ruby' => $ruby,
            'public_name' => $public_name,
            'total_unit_plot' => $total_unit_plot,
            'zip' => $zip,
            'location_code' => $location_code,
            'location_detail_open' => $location_detail_open,
            'location_detail_hide' => $location_detail_hide,
            'topography' => $topography,
            'land_measure_method' => $land_measure_method,
            'plot_area' => $plot_area,
            'burden_area' => $burden_area,
            'share_burden' => $share_burden,
            'ownership' => $ownership,
            'setback' => $setback,
            'setback_amount' => $setback_amount,
            'access_direction1' => $access_direction1,
            'access_frontage1' => $access_frontage1,
            'access_type1' => $access_type1,
            'access_width1' => $access_width1,
            'private_road1' => $private_road1,
            'access_direction2' => $access_direction2,
            'access_frontage2' => $access_frontage2,
            'access_type2' => $access_type2,
            'access_width2' => $access_width2,
            'private_road2' => $private_road2,
            'access_direction3' => $access_direction3,
            'access_frontage3' => $access_frontage3,
            'access_type3' => $access_type3,
            'access_width3' => $access_width3,
            'private_road3' => $private_road3,
            'access_direction4' => $access_direction4,
            'access_frontage4' => $access_frontage4,
            'access_type4' => $access_type4,
            'access_width4' => $access_width4,
            'private_road4' => $private_road4,
            'notification' => $notification,
            'restriction' => $restriction,
            'building_measure_method' => $building_measure_method,
            'site_area' => $site_area,
            'floor_area' => $floor_area,
            'architect_area' => $architect_area,
            'above_floor' => $above_floor,
            'under_floor' => $under_floor,
            'new_flag' => $new_flag,

            // 勤務形態
            'administrator' => $administrator,
            // 管理形態
            'manage_form' => $manage_form,
            'manage_union' => $manage_union,
            'manage_name' => $manage_name,
            'floor_num' => $floor_num,
            'balcony_area' => $balcony_area,
            // 向き
            'daylight_direction' => $daylight_direction,
            'url' => $url,
            'public_flag' => $public_flag,
            'price_state' => $price_state,
            'tax' => $tax,
            'tax_amount' => $tax_amount,
            'unit_price' => $unit_price,
            'common_manage_fee' => $common_manage_fee,
            'fee_tax' => $fee_tax,
            'repair_reserve' => $repair_reserve,
            'repair_fund' => $repair_fund,
            'expense_name1' => $expense_name1,
            'expense_cost1' => $expense_cost1,
            'expense_name2' => $expense_name2,
            'expense_cost2' => $expense_cost2,
            'expense_name3' => $expense_name3,
            'expense_cost3' => $expense_cost3,
            'transact_type' => $transact_type,
            'affiliate_group' => $affiliate_group,
            'recommend_num' => $recommend_num,
            'restrict_note' => $restrict_note,
            'building_note' => $building_note,
            'construct_name' => $construct_name,
            'renovation_date' => $renovation_date,
            'renovation_detail' => $renovation_detail,
            'structure_etc' => $structure_etc,
            'comment_temp_num' => $comment_temp_num,
            'panorama_id' => $panorama_id,
            'panorama_flag' => $panorama_flag,
            'panorama_del_flag' => $panorama_del_flag,
            'staff_comment_type' => $staff_comment_type,
            'staff_comment' => $staff_comment,
            'reform_water' => $reform_water,
            'reform_etc_water' => $reform_etc_water,
            'reform_done_water' => $reform_done_water,
            'reform_interior' => $reform_interior,
            'reform_etc_interior' => $reform_etc_interior,
            'reform_done_interior' => $reform_done_interior,
            'reform_exterior' => $reform_exterior,
            'reform_etc_exterior' => $reform_etc_exterior,
            'reform_done_exterior' => $reform_done_exterior,
            'reform_share' => $reform_share,
            'reform_done_share' => $reform_done_share,
            'reform_remark' => $reform_remark
        ];

        foreach ($fields as $key => $val) {
            update_post_meta($post_id, $key, $val);
        }
        // 最後に gallery-plugin 用のメタキーに保存
        update_post_meta($post_id, '_property_images', $gallery_images);
    }
    fclose($handle);

    // ==========================================
    // ★ 追加：取り下げ処理（CSVにない物件を非公開にする）
    // ==========================================
    if (!empty($processed_property_ids)) {
        // 現在「公開中」の全ての物件を取得
        $all_published_posts = get_posts([
            'post_type'   => 'property',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        foreach ($all_published_posts as $p_id) {
            $meta_id = get_post_meta($p_id, 'property_id', true);

            // WordPressにはあるが、今回のCSV（最新の掲載物件リスト）には含まれていない場合
            if (!in_array($meta_id, $processed_property_ids)) {
                wp_update_post([
                    'ID'          => $p_id,
                    'post_status' => 'private', // 'draft'（下書き）でも可。ここでは'private'（非公開）に設定
                ]);
                $count_withdrawn++;
            }
        }
    }

    return [
        'status' => 'success',
        'message' => "同期完了。新規: $count_new, 更新: $count_updated, 取り下げ: $count_withdrawn"
    ];
}
