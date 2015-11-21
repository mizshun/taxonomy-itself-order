<?php
/*
Plugin Name: Taxonomy Itself Order
Plugin URI:
Description: It is the plug in that can set the order of Taxonomy connected with a contribution type in a drag.
Version: 0.1.0
Author: Shunsuke Mizusawa
Author URI:
License:
Text Domain: taxonomy-itself-order
Domain Path: /languages
*/

if ( ! class_exists( 'Taxonomy_Itself_Order' ) ) :

/**
 * タクソノミーオーダー
 */
class Taxonomy_Itself_Order {

	const RESOURSE_PATH               = '/resource';
	const CSS_PATH                    = '/css';
	const JS_PATH                     = '/js';
	const FILE_NAME                   = 'taxodr-admin';
	const ALLOW_POST_TYPES_SETTING    = 'taxodr_allow_post_types';
	const TAXONOMY_ORDER_SETTING      = 'taxodr_taxonomy_order';
	const SETTING_WRAP_PREFIX         = 'taxodr-setting-taxonomy';
	const LIST_WRAP_PREFIX            = 'taxodr-list-taxonomy';
	const LIST_ITEM_PREFIX            = 'sort-item';

	private $dir                      = '';
	private $url                      = '';
	private $plugin_name              = '';
	private $action_name              = '';
	private $nonce                    = '';
	private $allow_post_types         = array();
	private $taxonomy_order           = array();
	private $header_data              = array();

	/**
	 * コンストラクター
	 */
	public function __construct() {
		// プラグインヘッダー情報
		$this->header_data = get_file_data( __FILE__, array(
			'version'     => 'Version',
			'plugin_name' => 'Plugin Name',
			'domain'      => 'Text Domain',
			'domain_path' => 'Domain Path',
		) );

		$this->dir         = untrailingslashit( plugin_dir_path( __FILE__ ) );
		$this->url         = untrailingslashit( plugin_dir_url( __FILE__ ) );
		$this->plugin_name = str_replace( ' ', '-', strtolower( $this->header_data['plugin_name'] ) );
		$this->action_name = str_replace( ' ', '_', strtolower( $this->header_data['plugin_name'] ) );

		// 翻訳
		// TODO 翻訳ファイル
		load_plugin_textdomain( $this->header_data['domain'], false, dirname( plugin_basename( __FILE__ ) ) . $this->header_data['domain_path'] );

		// セッティング
		$this->setting();

		// フック
		register_activation_hook  ( __FILE__, array( $this,'activate' ) );
		register_deactivation_hook( __FILE__, array( $this,'deactivate' ) );
		// TODO アンインストール時処理
		// register_uninstall_hook   ( __FILE__, 'taxonomy_itself_Order_uninstall' ); // 2.7+ 3.1以降はメソッド不可
		add_action( 'admin_menu',                           array( $this, 'register_submenu_page' ) );
		add_action( 'admin_init',                           array( $this, 'setting_option_page' ) );
		add_action( 'admin_enqueue_scripts',                array( $this, 'enqueue_necessary_files' ) );
		add_action( 'wp_ajax_' .        $this->action_name, array( $this, 'update_taxonomy_order' ) );
		add_action( 'wp_ajax_nopriv_' . $this->action_name, array( $this, 'update_taxonomy_order' ) );
	}

	/**
	 * サブメニューページを登録する
	 */
	public function register_submenu_page() {
		// プラグイン設定ページ
		add_options_page(
			__( 'Taxonomy Itself Order', $this->plugin_name ),
			__( 'Taxonomy Itself Order', $this->plugin_name ),
			"manage_categories",
			$this->plugin_name,
			array( $this, 'submenu_option_html' )
		);

		// 投稿タイプ別設定ページ
		foreach ( $this->allow_post_types as $allow_post_type ) {
			$parent_slug = "edit.php";
			if ( 'post' !== $allow_post_type ) {
				$parent_slug .= "?post_type={$allow_post_type}";
			}
			add_submenu_page(
				$parent_slug,
				__( 'Taxonomy Itself Order', $this->plugin_name ),
				__( 'Taxonomy Itself Order', $this->plugin_name ),
				"manage_categories",
				"{$allow_post_type}-{$this->plugin_name}",
				array( $this, 'submenu_page_html' )
			);
		}
	}

	/**
	 * 設定ページフォーム用
	 */
	public function setting_option_page() {
		// オプションを登録
		register_setting( 'settings-' . self::ALLOW_POST_TYPES_SETTING, self::ALLOW_POST_TYPES_SETTING );
	}

	/**
	 * 必要なファイルを読み込む
	 */
	public function enqueue_necessary_files() {
		$post_type = get_current_screen()->post_type ? : 'post';
		$css_path  = $this->url . self::RESOURSE_PATH . self::CSS_PATH . '/';
		$js_path   = $this->url . self::RESOURSE_PATH . self::JS_PATH . '/';

		if ( $this->is_allowed_admin_page() ) {
			// 設定ページ
			if ( isset( $_REQUEST['page'] ) && ( $this->plugin_name === $_REQUEST['page'] ) ) {
				wp_enqueue_style( self::FILE_NAME, $css_path . self::FILE_NAME . '.css' );
			}
			// 投稿タイプ別ページ
			else if ( $this->is_allowed_post_type( $post_type ) ) {
				wp_enqueue_style( self::FILE_NAME, $css_path . self::FILE_NAME . '.css' );
				wp_enqueue_script(
					self::FILE_NAME .'-js',
					$js_path . self::FILE_NAME . '.js',
					array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-sortable' ),
					$this->header_data['version'],
					true
				);

				$localize = array(
					'url'              => admin_url( 'admin-ajax.php' ),
					'action'           => $this->action_name,
					'nonce'            => wp_create_nonce( $this->nonce ),
					'postType'         => $post_type,
					'listWrapPrefix'   => 'taxodr-list-taxonomy',
					'listItemPrefix'   => 'sort-item'
				);
				wp_localize_script( self::FILE_NAME .'-js', $this->action_name, $localize );
			}
		}
	}

	/**
	 * AJAX処理を管理する
	 */
	public function update_taxonomy_order() {
		// チェック
		check_ajax_referer( $this->nonce, 'nonce' );
		if ( ! isset( $_POST['result'] ) || empty( $_POST['result'] ) ) { die(); }

		// 更新
		$new_order         = $this->conv_object( $_POST['result'] );
		$ordered           = array_merge( $this->taxonomy_order, $new_order );
		$seriarized_result = serialize( $ordered );


		error_log( print_r( $new_order, true), 3, '/Users/mizzz/Dropbox/Sites/dev/devwp/debug_log' );
		error_log( print_r( is_array(array()), true), 3, '/Users/mizzz/Dropbox/Sites/dev/devwp/debug_log' );
		// error_log( print_r( $ordered, true), 3, '/Users/mizzz/Dropbox/Sites/dev/devwp/debug_log' );
		error_log( print_r( $seriarized_result, true), 3, '/Users/mizzz/Dropbox/Sites/dev/devwp/debug_log' );

		$result = $this->update_option( self::TAXONOMY_ORDER_SETTING, $seriarized_result );
		// error_log( print_r((int)$_POST['debug'],true), 3, '/Users/mizzz/Dropbox/Sites/dev/devwp/debug_log' );
		// デバッグ
		if ( isset( $_POST['debug'] ) && (int) $_POST['debug'] ) {
			wp_send_json( $ordered );
		} else {
			wp_send_json( $result );
		}
	}

	/**
	 * オプションを更新する
	 * @param  string $option_name  オプション名
	 * @param  string $option_value オプション値
	 * @return boolean              成功時true,失敗時false
	 */
	private function update_option( $option_name, $option_value ) {
		$result;
		if ( is_multisite() ) {
			$result = update_blog_option( get_current_blog_id(), $option_name, (string) $option_value);
		} else {
			$result = update_option( $option_name, (string) $option_value );
		}
		return $result;
	}

	/**
	 * オプションを取得する
	 * @param  string $option_name  オプション名
	 * @return boolean              成功時は現在の値,失敗時false
	 */
	private function get_option( $option_name ) {
		$result;
		if ( is_multisite() ) {
			$result = get_blog_option( get_current_blog_id(), $option_name );
		} else {
			$result = get_option( $option_name );
		}
		return $result;
	}

	/**
	 * オプションを削除する
	 * @param  string $option_name  オプション名
	 * @return boolean              成功時true,失敗時false
	 */
	private function delete_option( $option_name ) {
		$result;
		if ( is_multisite() ) {
			$result = delete_blog_option( get_current_blog_id(), $option_name );
		} else {
			$result = delete_option( $option_name );
		}
		return $result;
	}

	/**
	 * プラグインに必要な設定
	 */
	private function setting() {
		$this->allow_post_types = $this->get_allow_post_types();
		$this->nonce            = $this->plugin_name;
		if ( $tax_order = $this->get_option( self::TAXONOMY_ORDER_SETTING ) ) {
			$this->taxonomy_order = unserialize( $tax_order );
		}
	}

	/**
	 * JSON文字列をオブジェクトに変換する
	 * @return object 変換したオブジェクト
	 */
	private function conv_object( $result ) {
		// TODO バックスラッシュがついてしまう原因確認
		$result = stripslashes( $result );
		$result = json_decode( $result, true );
		return $result;
	}

	/**
	 * 使用を許可した投稿タイプを取得する
	 * @return array 使用する投稿タイプ
	 */
	private function get_allow_post_types() {
		$allowed_post_types = $this->get_option( self::ALLOW_POST_TYPES_SETTING );

		if ( false === $allowed_post_types && ! $allowed_post_types ) { return array(); }
		// スペース、先頭末尾の「,」、連続した「,」を修正
		$allowed_post_types = preg_replace( '/,+/', ',', trim( str_replace( array( ' ', '　' ), '', $allowed_post_types ), ',' ) );
		$allowed_post_types = array_unique( explode( ',', $allowed_post_types ) );
		return $allowed_post_types;
	}

	/**
	 * スクリプトを使用する管理ページかどうか
	 * @return boolean  使用する管理ページかどうか
	 */
	private function is_allowed_admin_page() {
		global $pagenow;
		$allow_pages = array( 'options-general.php', 'edit.php' );
		return ( isset( $pagenow ) && in_array( $pagenow, $allow_pages ) );
	}

	/**
	 * 使用を許可した投稿タイプかどうか
	 * @param  string $post_type 現在の投稿タイプ
	 * @return boolean           現在の投稿タイプが使用を許可しているかどうか
	 */
	private function is_allowed_post_type( $post_type ) {
		return in_array( $post_type, $this->allow_post_types );
	}

	/**
	 * プラグイン設定のサブメニューページを作成する
	 */
	public function submenu_option_html() {
		// 不要ポストタイプを削除する
		$exist_post_types  = get_post_types( array( 'public' => true ) );
		$taxodr_post_types = $this->delete_unexist_post_type_to_string( $this->get_allow_post_types(), $exist_post_types );
	?>
		<div class="wrap">
			<h2><?php echo esc_html__( $this->header_data['plugin_name'], $this->plugin_name ) . ' ' . esc_html__( 'Configuration', $this->plugin_name ); ?></h2>
			<?php
			$page_desc = "<p>" . esc_html__( 'All the data about this plug in are deleted when I stop this plug in', $this->plugin_name ) . "</p>";
			echo apply_filters( "{$this->action_name}_setting_page_desc", $page_desc );
			?>
		</div>

		<div class="<?php echo $this->get_attribute_value( array( 'wrap', self::SETTING_WRAP_PREFIX ) ); ?>">
			<form method="post" action="options.php">
				<?php settings_fields( 'settings-' . self::ALLOW_POST_TYPES_SETTING ); ?>
				<table class="form-table">
					<tr>
						<th><?php echo esc_html__( 'Post type to allow Taxonomy order', $this->plugin_name ); ?></th>
						<td>
							<input type="text" name="<?php echo self::ALLOW_POST_TYPES_SETTING; ?>" value="<?php echo esc_attr( $taxodr_post_types ); ?>"
								placeholder="<?php echo apply_filters( "{$this->action_name}_setting_allowed_post_types_placeholder", esc_attr__( 'Please enter a post type separated by commas', $this->plugin_name ) ); ?>">
						</td>
					</tr
					><tr>
						<th>
							<?php echo esc_html__( 'How to get the taxonomy', $this->plugin_name ); ?><br />
							<small>( <?php echo esc_html__( 'Get taxonomy by the get_ordered_taxonomies method of the $taxodr instance', $this->plugin_name ); ?> )</small>
						</th>
						<td>
<textarea name="" rows="5" cols="80" readonly="readonly">
if ( function_exists( 'get_ordered_taxonomies' ) ) {
	$taxonomies = get_ordered_taxonomies( get_post_type() );
} else {
	$taxonomies = get_taxonomies( get_post_type() );
}
</textarea>
							<p><small><?php echo esc_html__( 'Can\'t edit this', $this->plugin_name ); ?></small></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
	<?php
	}

	/**
	 * 投稿タイプのサブメニューページを作成する
	 */
	public function submenu_page_html() {
		$post_type       = get_current_screen()->post_type ? get_current_screen()->post_type : 'post';
		$post_type_label = get_post_type_object( $post_type )->label;
		$is_admin        = true;
		$taxonomies      = $this->get_ordered_taxonomies( $post_type, $is_admin );
		$html            = '';
		?>

		<div class="wrap">
			<h2><?php echo apply_filters( "{$this->action_name}_page_title", esc_html__( $this->header_data['plugin_name'], $this->plugin_name ). ': ' . $post_type_label, $post_type ); ?></h2>
			<?php
			$page_desc  = "<p>" . esc_html__( 'All the data about this plug in are deleted when I stop this plug in', $this->plugin_name ) . "</p>";
			$page_desc .= "<p>" . esc_html__( 'Please drag taxonomy which you want to change', $this->plugin_name ) . "</p>";
			echo apply_filters( "{$this->action_name}_page_desc", $page_desc );
			?>
		</div>

		<?php
		// タクソノミーがあるとき
		if ( $taxonomies ) {
			// 属性
			$id    =  ' id="'    . $this->get_attribute_value( array( self::LIST_WRAP_PREFIX . '-' . esc_attr( $post_type ) ), $post_type, '', 'id'    ) . '"';
			$class =  ' class="' . $this->get_attribute_value( array( self::LIST_WRAP_PREFIX  ),                               $post_type, '', 'class' ) . '"';
			// 要素作
			$html  = '<div class="wrap">';
			$html .= '<ul' . $id . $class . '>';
			foreach ( $taxonomies as $key => $taxonomy) {
				// 属性
				$id   = ' id="'  . $this->get_attribute_value( array(  self::LIST_ITEM_PREFIX . '-' . $key ), $post_type, $taxonomy->name, 'id' ) . '"';
				$data = ' data-' . self::LIST_ITEM_PREFIX .'="' . esc_attr( $taxonomy->name ) . '"';
				// 要素作成
				$html .= '<li' . $id . $data . '>';
				$html .= esc_html( $taxonomy->label );
				$html .= '</li>';
			}
			$html .= '</ul>';
			$html .= '</div>';

		// タクソノミーがないとき
		} else {
			$html .= '<p>' . esc_html__( 'No Txonomy', $this->plugin_name ) . '</p>';
		}

		echo $html;
	}

	/**
	 * 要素の属性値を設定する
	 * @param  array  $attr_val      要素の属性値
	 * @param  string $post_type     現在の投稿タイプ
	 * @param  string $taxonomy_name 現在のタクソノミー
	 * @return array                 要素の属性値
	 */
	private function get_attribute_value( $attr_val, $post_type = '', $taxonomy_name = '', $attr_key = '' ) {
		$attr_val = apply_filters( "{$this->action_name}_get_attribute_value", $attr_val, $post_type, $taxonomy_name, $attr_key );
		return implode( ' ', $attr_val );
	}

	/**
	 * オーダーに基づいたタクソノミーを取得する
	 * @param  string $post_type 取得するタクソノミーの投稿タイプ
	 * @return array             タクソノミーオブジェクト
	 */
	public function get_ordered_taxonomies( $post_type = null, $is_admin = false ) {
		$ret_taxonomies       = array();
		$post_type            = $post_type ? : get_post_type(); // 管理画面$post_type必須
		$taxonomies           = get_object_taxonomies( $post_type, 'object' );
		$this->taxonomy_order = unserialize( $this->get_option( self::TAXONOMY_ORDER_SETTING ) );

		// タクソノミーかオーダー済みタクソノミーがなかったら処理しない
		if ( ! $taxonomies || ! $this->taxonomy_order || ! is_array( $this->taxonomy_order ) ) { return $taxonomies; }

		if ( isset( $this->taxonomy_order[$post_type] ) && count( $this->taxonomy_order[$post_type] ) ) {
			// 管理画面
			if ( $is_admin ) {
				// 不要タクソノミーを削除する
				$this->taxonomy_order[$post_type] = $this->delete_unexist_taxonomies( $this->taxonomy_order[$post_type], $taxonomies );
			}

			// タクソノミーを取得する
			foreach ( $this->taxonomy_order[$post_type] as $order ) {
				if ( isset( $taxonomies[$order] ) ) {
					$ret_taxonomies[$order] = $taxonomies[$order];
				}
			}
		}

		// 新規タクソノミーを最後尾に追加する
		$new_taxonomies = array_diff_key( $taxonomies, $ret_taxonomies );
		$ret_taxonomies = array_merge( $ret_taxonomies, $new_taxonomies );
		/**
		 * オーダーしたタクソノミー
		 * @param array $ret_taxonomies オーダー済みのタクソノミー
		 * @param array $taxonomies     オーダー前のタクソノミー
		 * @param string $is_admin      管理画面からの呼び出しかどうか
		 */
		// TODO ブログ特定
		return apply_filters( "{$post_type}_get_ordered_taxonomies", $ret_taxonomies, $taxonomies, $is_admin );
	}

	/**
	 * 不要ポストタイプを削除する
	 * @param  array $taxodr_post_types プラグインに登録されている投稿タイプ
	 * @param  array $exist_post_types  存在する投稿タイプ
	 * @return string                   存在する投稿タイプをカンマ区切りの文字列にしたもの
	 */
	private function delete_unexist_post_type_to_string( $taxodr_post_types, $exist_post_types ) {
		$taxodr_post_types   = implode( ',', array_intersect( $taxodr_post_types, $exist_post_types ) );
		return $taxodr_post_types;
	}

	/**
	 * 存在しないタクソノミーを削除する
	 * @param  array $taxodr_taxies プラグインに登録されているタクソノミー情報
	 * @param  array $exist_taxies  存在するタクソノミー
	 * @return array                存在しないタクソノミーを削除したタクソノミー情報
	 */
	private function delete_unexist_taxonomies( $taxodr_taxies, $exist_taxies ) {
		$unexist_keys = array_diff( $taxodr_taxies, array_keys( $exist_taxies ) );
		// 同じなら処理しない
		if ( ! count( $unexist_keys ) ) return $taxodr_taxies;
		// 削除
		foreach ( $unexist_keys as $unexist_key ) {
			if ( isset( $taxodr_taxies[$unexist_key] ) ) {
				unset( $taxodr_taxies[$unexist_key] );
				continue;
			}
		}
		return $taxodr_taxies;
	}

	/**
	 * 有効化時の処理
	 */
	public function activate() {
		// TODO マルチサイト対応
		if ( false === get_option( self::ALLOW_POST_TYPES_SETTING ) ) {
			$this->update_option( self::ALLOW_POST_TYPES_SETTING, '' );
		}
		if ( false === get_option( self::TAXONOMY_ORDER_SETTING ) ) {
			$this->update_option( self::TAXONOMY_ORDER_SETTING, serialize( array() ) );
		}
	}

	/**
	 * 無効化時の処理
	 */
	public function deactivate() {
		// TODO マルチサイト対応
		if ( false !== get_option( self::ALLOW_POST_TYPES_SETTING ) ) {
			$this->delete_option( self::ALLOW_POST_TYPES_SETTING );
		}
		if ( false !== get_option( self::TAXONOMY_ORDER_SETTING ) ) {
			$this->delete_option( self::TAXONOMY_ORDER_SETTING );
		}
	}
}

$taxodr = new Taxonomy_Itself_Order();

/**
 * 関数
 */
if ( isset( $taxodr ) && ! function_exists( 'get_ordered_taxonomies' ) ) {
	/**
	 * オーダーに基づいたタクソノミーを取得する
	 * @param  string $post_type 取得するタクソノミーの投稿タイプ
	 * @return array             タクソノミーオブジェクト
	 */
	function get_ordered_taxonomies( $post_type = null ) {
		global $taxodr;
		$taxonomies = array();

		if ( method_exists( $taxodr, 'get_ordered_taxonomies' ) ) {
			$taxonomies = $taxodr->get_ordered_taxonomies( $post_type );
		} else {
			$taxonomies = get_taxonomies( $post_type );
		}
		return $taxonomies;
	}
}

endif; // クラス存在チェック
