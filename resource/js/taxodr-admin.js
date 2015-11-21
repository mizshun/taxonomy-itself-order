/**
 * 管理画面でのオーダー機能
 */
jQuery(function($) {
	'use strict';

	// デバッグするかどうか
	var IS_DEBUG = true;

	/**
	 * デバッグ用関数
	 * @param  {mix}    target  デバッグする値
	 * @param  {string} useType コンソールの種類
	 */
	function d( target, useType ) {
		if ( ! window.console || ! IS_DEBUG ) { return; }
		var type = 'log';
		if      ( 'i' === useType ) { type = 'info'; }
		else if ( 'd' === useType ) { type = 'dir'; }
		else if ( 'e' === useType ) { type = 'error'; }
		else if ( 'w' === useType ) { type = 'warn'; }
		console[type]( target );
	}

	/**
	 * タクソノミーオーダークラス
	 */
	var TaxOdr = (function () {

		if ( ! taxonomy_itself_order) return false;

		/* ------------------------------
		 定数
		 ------------------------------*/
		var LIST_WRAP_PREFIX = taxonomy_itself_order.listWrapPrefix,
		    LIST_ITEM_PREFIX = taxonomy_itself_order.listItemPrefix,
		    REVRET_TIME      = 250;

		/* コンストラクタ */
		var that = function TaxOdr() {

			/* ------------------------------
			 メンバ
			 ------------------------------*/
			this.postType = '';

			/* ------------------------------
			 DOMアクセス
			 ------------------------------*/
			this.dom = {};
			this.dom.listTaxonomy = $( '[id^=' + LIST_WRAP_PREFIX + ']' );
			this.dom.taxonomies   = this.dom.listTaxonomy.find( '> div' );
		};

		/* パブリックメソッド */
		that.prototype = {
			/**
			 * 初期化する
			 */
			init: function () {
				d( 'Class: TaxOdr.init() Before', 'i' );

				if ( ! this.isItem() ) { return; }

				this.postType = taxonomy_itself_order.postType;

				/* ------------------------------
				 イベント
				 ------------------------------*/
				this.dom.listTaxonomy
					.sortable ({
						axis          : 'y',                  // 縦方向のみソート可能
						revert        : REVRET_TIME,          // 所定位置に戻るまでの時間
						update        : this.updateOrderAfter // 順序が変更されたとき更新する
					});

				d( 'Class: TaxOdr.init() After', 'i' );
			},

			/* ------------------------------
			 コントローラー
			 ------------------------------*/
			ctl: function () {},

			/* ------------------------------
			 ビュー
			 ------------------------------*/
			view: function () {},

			/* ------------------------------
			 モデル
			 ------------------------------*/
			/**
			 * タクソノミーがあるかどうか
			 * @return {Boolean} タクソノミーがあるかどうか
			 */
			isItem: function () {
				return !! this.dom.listTaxonomy.length;
			},

			/**
			 * オーダーの更新処理
			 * @param  {object} e  イベントオブジェクト
			 * @param  {object} ui オブジェクト
			 */
			updateOrderAfter: function ( e, ui ) {
				var curOrder   = $( this ).sortable( 'toArray' ),
					taxonomies = [],
					result     = {};

				$.each( curOrder, function ( i, id ) {
					var taxonomy = $( '#' + id ).data( LIST_ITEM_PREFIX );
					taxonomies.push( taxonomy );
				});

				result[taxonomy_itself_order.postType] = taxonomies;
				result = JSON.stringify( result ); // IE8+
				d( result );

				// 更新
				$.ajax({
					type  : "POST",
					url   : taxonomy_itself_order.url,
					data  : {
						action: taxonomy_itself_order.action,
						nonce : taxonomy_itself_order.nonce,
						result: result,
						//debug : 0 // オプション 0 or 1。1で変更後のデータを返す
					}
				})
				.done( function ( response, textStatus, jqXHR ) {
					d( response );
				} )
				.fail( function ( jqXHR, textStatus, errorThrown ) {
					// Code
				} )
				.always( function ( data /* or jqXHR */, textStatus, jqXHR /* or errorThrown */ ) {
					// Code
				} );
			}
		};

		return that;

	}());

	// 初期化
	( new TaxOdr() ).init();

});
