import ftplib
import os
import shutil
import logging
import codecs
import time
import pandas as pd
import requests
import zipfile
from datetime import datetime

# ==============================
# 1. 基本設定エリア
# ==============================
FTP_CONFIG = {
    "host": "www000.test.com",
    "user": "test@sample.com",
    "pass": "password",
}

SHOP_INFO = {
    "name": "株式会社サンプル不動産",
}

# ディレクトリ設定
LOCAL_TEMP_DIR = "./temp_ielove_data"
WP_IMPORT_DIR = "/home/c0000000/public_html/website.com/wp-content/uploads/wpallimport/files/"
# 自身の動作ログ
PYTHON_LOG_FILE = "./ielove_execution.log"

# FTP上のターゲット設定
REMOTE_TARGET_DIR = "/sale"   # 物件データがある場所
REMOTE_LOG_DIR = "/log"       # ログを置く場所

TARGET_CSV_NAME = "homes.csv"
SENT_FILE_NAME = "sent"


# ==============================
#  トリガー設定
# ==============================
WP_CUSTOM_TRIGGER_URL = "https://website.com/wp-json/simple-importer/v1/run?key=trigger-sample"


# エラーコード定義
ERROR_DEFINITIONS = {
    "SUCCESS": "正常受付",
    "ERR_REQUIRED": "必須項目エラー",
    "ERR_TYPE": "項目エラー(種別)",
    "ERR_CRITICAL": "受付不可",
    "ERR_NOT_PUBLISHED": "非掲載（状態が1以外）"
}

LOG_COLUMNS = [
    "店舗名", "取り込み完了時刻", "処理件数", "取り込み件数", "受付不可件数",
    "弊社物件番号", "エラー種別", "エラー内容", "貴社の物件番号"
]

# 画像用
IMAGE_COLUMNS = list(range(224, 248)) + list(range(272, 368))

# ログ設定 (Python 3.6対応: 日本語文字化け対策)
logger = logging.getLogger()
logger.setLevel(logging.INFO)
if logger.hasHandlers():
    logger.handlers.clear()

handler = logging.FileHandler(PYTHON_LOG_FILE, encoding='utf-8')
handler.setFormatter(logging.Formatter('%(asctime)s - %(message)s'))
logger.addHandler(handler)


# ==============================
# 新規追加: 緯度経度変換ロジック
# ==============================
def convert_ielove_coord(coord_str):
    """
    '36.21.21.989/139.6.37.478' のような形式を
    '36.356108,139.110411' (Googleマップ形式) に変換する
    """
    if not isinstance(coord_str, str) or '/' not in coord_str:
        return coord_str

    try:
        parts_main = coord_str.split('/')
        decimal_results = []

        for s in parts_main:
            p = s.split('.')
            if len(p) >= 3:
                # 度 + 分/60 + 秒/3600
                deg = float(p[0])
                minute = float(p[1]) / 60
                
                # 秒の部分（小数点以下がある場合は連結して1つの数値にする）
                sec_val = p[2]
                if len(p) >= 4:
                    sec_val = f"{p[2]}.{p[3]}"
                
                sec = float(sec_val) / 3600
                # 小数点第7位まで丸める（Googleマップで十分な精度）
                decimal_results.append(str(round(deg + minute + sec, 7)))
            else:
                decimal_results.append(s)
        
        # カンマ区切りで結合 (lat,lon)
        return ",".join(decimal_results)
    except:
        return coord_str


# ==============================
# 2. バリデーション（審査）& HTML生成ロジック
# 一旦主データ内の必須項目のみチェックする
# ==============================
def validate_row(row):
    """
    HOME'S CSV仕様書(Ver.4.3.0)の「■(必須)」「～以外はエラー」を網羅した判定
    """
    # インデックス定義 (CSV No - 1)
    IDX_ID = 0      # 物件番号(1)
    IDX_STATE = 5   # 状態(6)

    # 厳格ver
    # IDX = {
    #     "ID": 0, "PUB_FLAG": 3, "OWN_FLAG": 4, "STATE": 5, "TYPE": 6,
    #     "NAME": 9, "ZIP": 15, "ADDR": 17,
    #     "LINE": 21, "STATION": 22, "WALK": 25,
    #     "LAND_USE": 33, "ZONING": 34, "URBAN_PLAN": 35, "LAND_AREA": 38,
    #     "RIGHTS": 67, "STRUCTURE": 70, "BUILD_AREA": 72, "FLOORS": 76, "DATE": 78,
    #     "ADMIN_TYPE": 80, "ADMIN_FORM": 81,
    #     "PRICE": 138, "GENKYO": 183, "DELIVERY": 184, "CONFIRM_NO": 254
    # }

    try:
        def is_empty(val):
            v = str(val).strip().lower()
            return v in ["", "nan", "none", "*"]

        # --- 【追加】状態(6) のチェック ---
        # 1以外は全て非掲載とする（WordPress用CSVに含めない）
        state_val = str(row[IDX_STATE]).strip()
        if state_val != "1":
            return "ERR_NOT_PUBLISHED", f"状態が'{state_val}'のため非掲載として処理します"

        # --- 必須チェック2: 物件番号(1) ---
        if is_empty(row[IDX_ID]):
            return "ERR_REQUIRED", "物件番号(1)が空です"

        # 以下厳格verのロジック参考
        # p_type = str(row[IDX["TYPE"]]).strip()

        # 1. 絶対必須項目 (■マーク項目)
        # must_items = [
        #     (IDX["ID"], "物件番号(1)"),
        #     (IDX["PUB_FLAG"], "公開可否(4)"),
        #     (IDX["STATE"], "状態(6)"),
        #     (IDX["TYPE"], "物件種別(7)"),
        #     (IDX["NAME"], "物件名(10)"),
        #     (IDX["ZIP"], "郵便番号(16)"),
        #     (IDX["ADDR"], "所在地名称(18)"),
        #     (IDX["PRICE"], "価格(139)")
        # ]

        # for idx, label in must_items:
        #     if is_empty(row[idx]):
        #         return "ERR_REQUIRED", f"{label}は必須です"

        # 2. 交通の整合性 (一つでもあれば他も必須)
        # if not is_empty(row[IDX["LINE"]]) or not is_empty(row[IDX["STATION"]]):
        #     if is_empty(row[IDX["LINE"]]): return "ERR_TYPE", "路線名(22)が未入力です"
        #     if is_empty(row[IDX["STATION"]]): return "ERR_TYPE", "駅名(23)が未入力です"
        #     if is_empty(row[IDX["WALK"]]): return "ERR_TYPE", "徒歩距離(26)が未入力です"

        # 3. 物件種別ごとの詳細バリデーション
        # 土地 (11xx)
        # if p_type.startswith('11'):
        #     if is_empty(row[IDX["LAND_USE"]]): return "ERR_REQUIRED", "地目(34)は必須です"
        #     if is_empty(row[IDX["ZONING"]]): return "ERR_REQUIRED", "用途地域(35)は必須です"
        #     if is_empty(row[IDX["URBAN_PLAN"]]): return "ERR_REQUIRED", "都市計画(36)は必須です"
        #     if is_empty(row[IDX["LAND_AREA"]]): return "ERR_REQUIRED", "土地面積(39)は必須です"
        #     if is_empty(row[IDX["RIGHTS"]]): return "ERR_REQUIRED", "土地権利(68)は必須です"

        # 建物系 (12xx:戸建, 13xx:マンション, 14xx/15xx:店舗等)
        # elif p_type.startswith(('12', '13', '14', '15')):
        #     if is_empty(row[IDX["STRUCTURE"]]): return "ERR_REQUIRED", "建物構造(71)は必須です"
        #     if is_empty(row[IDX["BUILD_AREA"]]): return "ERR_REQUIRED", "建物/専有面積(73)は必須です"
        #     if is_empty(row[IDX["DATE"]]): return "ERR_REQUIRED", "築年月(79)は必須です"
        #     if is_empty(row[IDX["ADMIN_TYPE"]]): return "ERR_REQUIRED", "管理人(81)は必須です"
        #     if is_empty(row[IDX["ADMIN_FORM"]]): return "ERR_REQUIRED", "管理形態(82)は必須です"

            # 新築戸建(1201)の特有ルール
            # if p_type == "1201":
            #     if is_empty(row[IDX["CONFIRM_NO"]]):
            #         return "ERR_REQUIRED", "新築戸建は建築確認番号(255)が必須です"

        # 4. 論理チェック (現況と引渡)
        # genkyo = str(row[IDX["GENKYO"]]).strip()
        # delivery = str(row[IDX["DELIVERY"]]).strip()
        # 建物系で 現況が 居住中(1), 賃貸中(3), 未完成(4) の場合
        # if p_type.startswith(('12', '13', '14', '15')) and genkyo in ['1', '3', '4']:
        #     if delivery == '1': # 即時(1)はエラー
        #         return "ERR_CRITICAL", f"現況({genkyo})に対し引渡'即時'は選択不可です(No.185)"

        return "SUCCESS", ""
    except Exception as e:
        return "ERR_CRITICAL", f"システムエラー: {e}"


# ==============================
# 3. 処理メイン関数
# ==============================
def process_csv_and_log(local_csv_path, local_log_path, wp_csv_path):
    try:
        # 全てのデータを文字列として読み込み
        df_all = pd.read_csv(
            local_csv_path,
            encoding='cp932',
            header=None,
            names=range(419),  # 419列分を確保
            dtype=str,
            sep=None,
            engine='python'
        )

        if len(df_all) < 2:
            logger.warning("CSVの行数が不足しています。")
            return False

        # 2. 2行目以降をデータ本体とする
        df = df_all.iloc[1:].reset_index(drop=True)

        total_count = len(df)
        success_count = 0
        fail_count = 0
        timestamp = datetime.now().strftime("%Y/%m/%d %H:%M:%S")
        log_data = []
        valid_rows_indices = []

        df['generated_html'] = ""
        df['wp_members_block'] = ""

        for index, row in df.iterrows():
            # 物件番号の取得（ログ用）
            bukken_id = str(row[0]).strip() if not pd.isna(row[0]) else "Unknown"
            
            # --- 【追加】 緯度経度の変換処理 (20番目の列 = U列) ---
            raw_coord = str(row[20]).strip() if not pd.isna(row[20]) else ""
            if raw_coord:
                converted_coord = convert_ielove_coord(raw_coord)
                df.at[index, 20] = converted_coord

            # バリデーション実行
            err_code, err_msg = validate_row(row)
            error_type_text = ERROR_DEFINITIONS.get(err_code, "不明なエラー")
            
            if err_code == "SUCCESS":
                success_count += 1
                valid_rows_indices.append(index) # 有効なインデックスを保存
                
                # 138列目（インデックス137）の値を確認
                row_val_137 = str(row[137]).strip().lower() if not pd.isna(row[137]) else ""

                # PHP側の if ($wpm_block_flag === 'no') { 制限解除 } に合わせる
                if row_val_137 == "common":
                    df.at[index, 'wp_members_block'] = "no"  #一般 ブロックしない
                else:
                    df.at[index, 'wp_members_block'] = "yes" #限定 ブロック
            else:
                fail_count += 1

            # ログデータ作成
            log_row = {
                "店舗名": SHOP_INFO["name"],
                "取り込み完了時刻": timestamp,
                "処理件数": total_count,
                "取り込み件数": 0,
                "受付不可件数": 0,
                "弊社物件番号": bukken_id,
                "エラー種別": error_type_text,
                "エラー内容": err_msg,
                "貴社の物件番号": bukken_id
            }
            log_data.append(log_row)

        # ログ書き出し
        for row_data in log_data:
            row_data["取り込み件数"] = success_count
            row_data["受付不可件数"] = fail_count

        result_df = pd.DataFrame(log_data)
        result_df = result_df[LOG_COLUMNS]
        result_df.to_csv(local_log_path, index=False, header=True, encoding='cp932')
        logger.info(f"ログ作成完了: {local_log_path}")

        if valid_rows_indices:
            clean_df = df.iloc[valid_rows_indices]
            clean_df.to_csv(wp_csv_path, index=False, header=False, encoding='utf-8')
            logger.info(f"WP用クリーンCSV作成完了: {len(clean_df)}件 (HTML列追加済み)")
        else:
            logger.warning("有効な物件なし")

        return True
    except Exception as e:
        logger.error(f"CSV処理エラー: {e}", exc_info=True)
        return False

def extract_zip_images(zip_path, extract_to_dir):
    try:
        if not zipfile.is_zipfile(zip_path):
            return False
        with zipfile.ZipFile(zip_path, 'r') as zip_ref:
            zip_ref.extractall(extract_to_dir)
            extracted_files = zip_ref.namelist()
            logger.info(f"Zip解凍完了: {os.path.basename(zip_path)} -> {len(extracted_files)}ファイル展開")
        return True
    except Exception as e:
        logger.error(f"Zip解凍エラー: {e}")
        return False

# 自作プラグイン用のトリガー
def trigger_custom_import():
    try:
        logger.info("自作プラグインによるインポートを開始します...")
        logger.info(f"URL: {WP_CUSTOM_TRIGGER_URL}")

        response = requests.get(WP_CUSTOM_TRIGGER_URL, timeout=100) # 30

        if response.status_code == 200:
            logger.info(f"インポート成功: {response.text}")
        else:
            logger.error(f"インポート失敗 (Status: {response.status_code}): {response.text}")

    except Exception as e:
        logger.error(f"WP連携エラー: {e}")

def main():
    logger.info("--- 処理開始 ---")

    if not os.path.exists(LOCAL_TEMP_DIR):
        os.makedirs(LOCAL_TEMP_DIR)

    downloaded_files = []

    try:
        # 1. FTP接続
        ftp = ftplib.FTP(FTP_CONFIG["host"])
        ftp.login(FTP_CONFIG["user"], FTP_CONFIG["pass"])

        # sale ディレクトリへ移動
        try:
            ftp.cwd(REMOTE_TARGET_DIR)
            logger.info(f"ディレクトリ移動: {REMOTE_TARGET_DIR}")
        except Exception as e:
            logger.error(f"ディレクトリ移動失敗 ({REMOTE_TARGET_DIR}): {e}")
            ftp.quit()
            return

        files = ftp.nlst()
        target_files = []
        for f in files:
            if f == TARGET_CSV_NAME or f.lower().endswith('.zip'):
                target_files.append(f)
            elif SENT_FILE_NAME in f:
                target_files.append(f)

        if not target_files:
            logger.info("処理対象ファイルがありません。終了します。")
            ftp.quit()
            return

        # ダウンロード
        for filename in target_files:
            local_path = os.path.join(LOCAL_TEMP_DIR, filename)
            with open(local_path, 'wb') as f:
                ftp.retrbinary(f"RETR {filename}", f.write)
            downloaded_files.append(filename)
            logger.info(f"ダウンロード: {filename}")

        # 2. CSV処理
        csv_processed = False
        if TARGET_CSV_NAME in downloaded_files:
            local_csv = os.path.join(LOCAL_TEMP_DIR, TARGET_CSV_NAME)
            today_str = datetime.now().strftime("%Y%m%d")
            report_filename = f"{today_str}_log.csv"
            local_log = os.path.join(LOCAL_TEMP_DIR, report_filename)
            wp_clean_csv = os.path.join(LOCAL_TEMP_DIR, "clean_" + TARGET_CSV_NAME)
            
            if process_csv_and_log(local_csv, local_log, wp_clean_csv):
                csv_processed = True

                # ログ用ディレクトリへ移動してアップロード
                try:
                    ftp.cwd('/')         # ルートへ
                    ftp.cwd(REMOTE_LOG_DIR) # logディレクトリへ
                    with open(local_log, 'rb') as f:
                        ftp.storbinary(f"STOR {report_filename}", f)
                    # logger.info(f"報告用ログFTP送信完了: {REMOTE_LOG_DIR}/{report_filename}")
                    logger.info(f"報告用ログFTP送信完了")
                    
                    # 元のディレクトリ(sale)に戻る
                    ftp.cwd('/')
                    ftp.cwd(REMOTE_TARGET_DIR)
                    
                except Exception as e:
                    logger.error(f"ログアップロード失敗: {e}")

                # WP用CSV配置
                if os.path.exists(wp_clean_csv):
                    if not os.path.exists(WP_IMPORT_DIR):
                        os.makedirs(WP_IMPORT_DIR)
                    dst_path = os.path.join(WP_IMPORT_DIR, TARGET_CSV_NAME)
                    shutil.move(wp_clean_csv, dst_path)
                    logger.info(f"WP用CSV配置完了: {dst_path}")

        # 3. 画像(Zip)処理
        for filename in downloaded_files:
            if filename.lower().endswith('.zip'):
                local_zip_path = os.path.join(LOCAL_TEMP_DIR, filename)
                if not os.path.exists(WP_IMPORT_DIR):
                    os.makedirs(WP_IMPORT_DIR)
                extract_zip_images(local_zip_path, WP_IMPORT_DIR)

        # 修正の間コメントアウト
        # 4. FTP上のファイル削除
        # csv_processed が True（成功）の場合のみ削除を実行する
        if csv_processed:
            for filename in downloaded_files:
                try:
                    ftp.delete(filename)
                    logger.info(f"FTP削除: {filename}")
                except Exception as e:
                    logger.error(f"FTP削除失敗: {filename} - {e}")
        else:
            # エラーがあった場合やCSVがなかった場合は削除をスキップ
            logger.warning("CSV処理が正常に完了しなかったため、FTP上のファイル削除をスキップしました。")

    except Exception as e:
        logger.error(f"FTPエラー: {e}")
        return

    try: shutil.rmtree(LOCAL_TEMP_DIR)
    except: pass

    # 5. 自作プラグインを実行
    if csv_processed:
        trigger_custom_import()

    logger.info("--- 全処理完了 ---")

if __name__ == "__main__":
    main()
    print("スクリプトの実行が完了しました")