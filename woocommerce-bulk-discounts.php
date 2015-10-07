<?php
/*
Plugin Name: WooCommerce Bulk Discounts
Plugin URI: https://github.com/jessepearson/woocommerce-bulk-discounts
Description: Apply fine-grained bulk discounts to items in the shopping cart.
Author: Jesse Pearson ( original Rene Puchinger )
Version: 2.3.1
Author URI: https://jessepearson.net
License: GPL3
*/

// Exit if accessed directly
if( ! defined( 'ABSPATH' ) )
	exit; 

// Check if WooCommerce is active
if( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
	return;

// check if the class exists
if( ! class_exists( 'WooCommerce_Bulk_Discounts' ) ) {

	// create the class
	class WooCommerce_Bulk_Discounts {

		var $discount_coeffs;
		var $bulk_discount_calculated = false;

		public function __construct() {

			load_plugin_textdomain( 'wc_bulk_discount', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

			$this->current_tab = ( isset( $_GET[ 'tab' ] ) ) ? $_GET[ 'tab' ] : 'general';

			$this->settings_tabs = array(
				'bulk_discount' => __( 'Bulk Discount', 'wc_bulk_discount' )
			);

			add_action( 'admin_enqueue_scripts', array( $this, 'action_enqueue_dependencies_admin' ) );
			add_action( 'wp_head', array( $this, 'action_enqueue_dependencies' ) );

			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );

			add_action( 'woocommerce_settings_tabs', array( $this, 'add_tab' ), 10 );

			// Run these actions when generating the settings tabs.
			foreach( $this->settings_tabs as $name => $label ) {
				add_action( 'woocommerce_settings_tabs_' . $name, array( $this, 'settings_tab_action' ), 10 );
				add_action( 'woocommerce_update_options_' . $name, array( $this, 'save_settings' ), 10 );
			}

			// Add the settings fields to each tab.
			add_action( 'woocommerce_bulk_discount_settings', array( $this, 'add_settings_fields' ), 10 );

			add_action( 'woocommerce_loaded', array( $this, 'woocommerce_loaded' ) );

		}

		/**
         * Main processing hooks
		 */
		public function woocommerce_loaded() {

			if( get_option( 'woocommerce_t4m_enable_bulk_discounts', 'yes' ) == 'yes' ) {

				add_action( 'woocommerce_before_calculate_totals',	array( $this, 'action_before_calculate' ), 10, 1 );
				add_action( 'woocommerce_calculate_totals',			array( $this, 'action_after_calculate' ), 10, 1 );
				add_action( 'woocommerce_before_cart_table',		array( $this, 'before_cart_table' ) );
				add_action( 'woocommerce_single_product_summary',	array( $this, 'single_product_summary' ), 45 );
				add_filter( 'woocommerce_cart_item_subtotal',		array( $this, 'filter_subtotal_price' ), 15, 2 );
				add_filter( 'woocommerce_checkout_item_subtotal',	array( $this, 'filter_subtotal_price' ), 15, 2 );
				add_filter( 'woocommerce_order_formatted_line_subtotal',	array( $this, 'filter_subtotal_order_price' ), 10, 3 );
				add_filter( 'woocommerce_product_write_panel_tabs',	array( $this, 'action_product_write_panel_tabs' ) );
				add_filter( 'woocommerce_product_write_panels',		array( $this, 'action_product_write_panels' ) );
				add_action( 'woocommerce_process_product_meta',		array( $this, 'action_process_meta' ) );
				add_filter( 'woocommerce_cart_product_subtotal',	array( $this, 'filter_cart_product_subtotal' ), 10, 3 );
				add_action( 'woocommerce_checkout_update_order_meta',	array( $this, 'order_update_meta' ) );

				if( version_compare( WOOCOMMERCE_VERSION, "2.1.0" ) >= 0 ) {
					add_filter( 'woocommerce_cart_item_price',			array( $this, 'filter_item_price' ), 10, 2 );
					add_filter( 'woocommerce_update_cart_validation',	array( $this, 'filter_before_calculate' ), 10, 1 );
				} else {
					add_filter( 'woocommerce_cart_item_price_html',		array( $this, 'filter_item_price' ), 10, 2 );
				}
			}
		}

		/**
		 * Add action links under WordPress > Plugins
		 *
		 * @param $links
		 * @return array
		 */
		public function action_links( $links ) {
		
			$settings_slug = 'woocommerce';
		
			if( version_compare( WOOCOMMERCE_VERSION, "2.1.0" ) >= 0 ) {
				
				$settings_slug = 'wc-settings';			
			}

			$plugin_links = array(
				'<a href="' . admin_url( 'admin.php?page=' . $settings_slug . '&tab=bulk_discount' ) . '">' . __( 'Settings', 'woocommerce' ) . '</a>',
			);

			return array_merge( $plugin_links, $links );
		}

		/**
		 * For given product, and quantity return the price modifying factor (percentage discount) or value to deduct (flat discount).
		 *
		 * @param $product_id
		 * @param $quantity
		 * @param $order
		 * @return float
		 */
		protected function get_discounted_coeff( $product_id, $quantity ) {

			$q = array( 0.0 );
			$d = array( 0.0 );

			/* Find the appropriate discount coefficient by looping through up to the five discount settings */
			for( $i = 1; $i <= 5; $i++ ) {
				
				array_push( $q, get_post_meta( $product_id, "_bulkdiscount_quantity_$i", true ) );
				
				if( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) {
					array_push( $d, get_post_meta( $product_id, "_bulkdiscount_discount_flat_$i", true ) ? get_post_meta( $product_id, "_bulkdiscount_discount_flat_$i", true ) : 0.0 );
				} else {
					array_push( $d, get_post_meta( $product_id, "_bulkdiscount_discount_$i", true ) ? get_post_meta( $product_id, "_bulkdiscount_discount_$i", true ) : 0.0 );
				}

				if( $quantity >= $q[$i] && $q[$i] > $q[0] ) {
					$q[0] = $q[$i];
					$d[0] = $d[$i];
				}
			}

			// for percentage discount convert the resulting discount from % to the multiplying coefficient
			return ( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) ? max( 0, $d[0] ) : min( 1.0, max( 0, ( 100.0 - round( $d[0], 2 ) ) / 100.0 ) );
		}

		/**
		 *
		 */
		public function get_bundle_price( $price, $values ) {

			// set starting price
			$price = 0;

			// go through each item
			foreach( $values[ 'stamp' ] as $item ) {

				// determine the type, create new object for each, then get its price
				switch( $item[ 'type' ] ) {
					case 'variable':
						$prod 		= new WC_Product_Variation( $item[ 'product_id' ] );
						$p_price 	= $prod->get_price();
						break;
					
					default: // simple
						$prod 		= new WC_Product_Simple( $item[ 'product_id' ] );
						$p_price 	= $prod->get_price();
						break;
				}

				// if there is a discount
				if( $item[ 'discount' ] != 0 ) {
					
					// subtract the discount from the price
					$p_price = $p_price - ( ( $item[ 'discount' ] / 100 ) * $p_price );
				}

				// add the item's price to the total
				$price = $price + ( $p_price * $item[ 'quantity' ] ); 
			}

			// format and return
			return number_format( $price, 2 );
		}

		/**
		 * Filter product price so that the discount is visible.
		 *
		 * @param $price
		 * @param $values
		 * @return string
		 */
		public function filter_item_price( $price, $values ) {

			if( !$values || @!$values[ 'data' ] ) {
				return $price;
			}

			if( $this->coupon_check() ) {
				return $price;
			}

			$_product = $values[ 'data' ];
			if( get_post_meta( $_product->id, "_bulkdiscount_enabled", true ) != '' && get_post_meta( $_product->id, "_bulkdiscount_enabled", true ) !== 'yes' ) {
				return $price;
			}

			if( ( get_option( 'woocommerce_t4m_show_on_item', 'yes' ) == 'no' ) ) {
				return $price;
			}

			if( ( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) ) {
				return $price; // for flat discount this filter has no meaning
			}

			if( empty( $this->discount_coeffs ) 
				|| !isset( $this->discount_coeffs[$this->get_actual_id( $_product )] )
				|| !isset( $this->discount_coeffs[$this->get_actual_id( $_product )][ 'orig_price' ] ) 
				|| !isset( $this->discount_coeffs[$this->get_actual_id( $_product )][ 'coeff' ] ) ) {

				$this->gather_discount_coeffs();
			}

			// if it's in a bundle
			if( isset( $values[ 'bundled_by' ] ) ) {

				// get the global
				global $woocommerce;

				// get the bundle id from the cart
				$bundle_id = $woocommerce->cart->cart_contents[ $values[ 'bundled_by' ] ][ 'product_id' ];

				// get the coeff for that id
				$coeff = $this->discount_coeffs[ $bundle_id ][ 'coeff' ];

			} else {
				$coeff = $this->discount_coeffs[$this->get_actual_id( $_product )][ 'coeff' ];
			}

			// if it's 1, there's no price modification
			if( $coeff == 1.0 )
				return $price;

			// if it's a bundle
			if( $values[ 'data' ] instanceof WC_Product_Bundle ) {

				// return the price as is, bundles plugin hides it
				return $price;

				// get the bundled price
				$price = $this->get_bundle_price( $price, $values );
				$discprice 	= woocommerce_price( $price * $coeff );
				$oldprice 	= woocommerce_price( $price );
			} else {

				// get the new and old prices
				$discprice 	= woocommerce_price( $_product->get_price() * $coeff );
				$oldprice 	= woocommerce_price( $this->discount_coeffs[$this->get_actual_id( $_product )][ 'orig_price' ] );
			}

			
			$old_css 	= esc_attr( get_option( 'woocommerce_t4m_css_old_price', 'color: #777; text-decoration: line-through; margin-right: 4px;' ) );
			$new_css 	= esc_attr( get_option( 'woocommerce_t4m_css_new_price', 'color: #4AB915; font-weight: bold;' ) );

			return "<span class='discount-info' title='" . sprintf( __( '%s%% bulk discount applied!', 'wc_bulk_discount' ), round( ( 1.0 - $coeff ) * 100.0, 2 ) ) . "'>" .
			"<span class='old-price' style='$old_css'>$oldprice</span>" .
			"<span class='new-price' style='$new_css'>$discprice</span></span>";
		}

		/**
		 * Filter product price so that the discount is visible.
		 *
		 * @param $price
		 * @param $values
		 * @return string
		 */
		public function filter_subtotal_price( $price, $values ) {

			// if there's not values, return
			if( !$values || !$values[ 'data' ] )
				return $price;

			// if there's a coupon, return
			if( $this->coupon_check() )
				return $price;

			// get the product, if bulk discount is not enabled, return
			$_product = $values[ 'data' ];
			if( ! $this->is_bulkdiscount_enabled( $_product->id ) )
				return $price;

			// if we want to show the discount on subtotal, continue
			if( ( get_option( 'woocommerce_t4m_show_on_subtotal', 'yes' ) == 'no' ) )
				return $price;
			
			// make sure we have the coeffs
			if( empty( $this->discount_coeffs ) 
				|| !isset( $this->discount_coeffs[$this->get_actual_id( $_product )] )
				|| !isset( $this->discount_coeffs[$this->get_actual_id( $_product )][ 'orig_price' ] ) 
				|| !isset( $this->discount_coeffs[$this->get_actual_id( $_product )][ 'coeff' ] )
			) {
				$this->gather_discount_coeffs();
			}

			// if it's in a bundle
			if( isset( $values[ 'bundled_by' ] ) ) {

				// get the global
				global $woocommerce;

				// get the bundle id from the cart
				$bundle_id = $woocommerce->cart->cart_contents[ $values[ 'bundled_by' ] ][ 'product_id' ];

				// get the coeff for that id
				$coeff = $this->discount_coeffs[ $bundle_id ][ 'coeff' ];

				// set the proper subtotal
				$price = number_format( $values[ 'line_subtotal' ], 2 );
				$price = get_woocommerce_currency_symbol() . $price;

				// return
				return "<span>$price</span>";

			} else {
				$coeff = $this->discount_coeffs[$this->get_actual_id( $_product )][ 'coeff' ];
			}

			// get the discount type
			$discount_type = get_option( 'woocommerce_t4m_discount_type', '' );

			// no price modification
			if( ( $discount_type == 'flat' && $coeff == 0 ) || ( $discount_type == '' && $coeff == 1.0 ) )
				return $price; 

			// if it's a bundle
			if( $values[ 'data' ] instanceof WC_Product_Bundle ) 
				$price = get_woocommerce_currency_symbol() . $this->get_bundle_price( $price, $values );
			
			// set new data
			$new_css 	= esc_attr( get_option( 'woocommerce_t4m_css_new_price', 'color: #4AB915; font-weight: bold;' ) );
			$bulk_info 	= sprintf( 
				__( 'Incl. %s discount', 'wc_bulk_discount' ),
				( $discount_type == 'flat' ? get_woocommerce_currency_symbol() . $coeff : ( round( ( 1 - $coeff ) * 100, 2 ) . "%" ) ) );

			return "<span class='discount-info' title='$bulk_info'>" .
			"<span>$price</span>" .
			"<span class='new-price' style='$new_css'> ($bulk_info)</span></span>";
		}

		/**
		 * Gather discount information to the array $this->discount_coefs
		 */
		protected function gather_discount_coeffs() {

			// get the global
			global $woocommerce;

			// set the cart and the coeffs
			$cart = $woocommerce->cart;
			$this->discount_coeffs = array();

			// if there's nothing in the cart, exit
			if( sizeof( $cart->cart_contents ) <= 0 )
				return;

			// go through each item in the cart
			foreach( $cart->cart_contents as $values ) {

				// set the product and the default quantity
				$_product = $values[ 'data' ];
				$quantity = 0;

				// if we're not processing variations differently
				if( get_option( 'woocommerce_t4m_variations_separate', 'yes' ) == 'no' && $_product instanceof WC_Product_Variation && $_product->parent ) {

					// get the variation quantity
					$quantity = $this->gather_discount_coeffs_variations( $_product, $_product->parent, $cart->cart_contents );

				// if it's a bundle
				} elseif( $_product instanceof WC_Product_Bundle ) {

					// set the quantity of the bundle
					$quantity 	= ( isset( $this->discount_coeffs[ $_product->id ][ 'quantity' ] ) ) ? $this->discount_coeffs[ $_product->id ][ 'quantity' ] : $quantity;
					$quantity 	= $quantity + $values[ 'quantity' ];
					$this->discount_coeffs[ $_product->id ][ 'quantity' ] = $quantity;

				// we're skipping products in bundles
				} elseif( isset( $values[ 'bundled_by' ] ) ) {

					//continue;

				// default
				} else {
					$quantity = $values[ 'quantity' ];
				}

				// set the coeff and original prices
				$this->discount_coeffs[ $this->get_actual_id( $_product ) ][ 'coeff' ] 		= $this->get_discounted_coeff( $_product->id, $quantity );
				$this->discount_coeffs[ $this->get_actual_id( $_product ) ][ 'orig_price' ] = $_product->get_price();
			}
		}

		/**
		 * 
		 */
		protected function gather_discount_coeffs_variations( $product, $parent, $cart_contents ) {

			$quantity = 0;

			foreach( $cart_contents as $valuesInner ) {

				$p = $valuesInner[ 'data' ];

				if( $p instanceof WC_Product_Variation && $p->parent && $p->parent->id == $parent->id ) {
					$quantity += $valuesInner[ 'quantity' ];
					$this->discount_coeffs[$product->variation_id][ 'quantity' ] = $quantity;
				}
			}
			
			return $quantity;
		}

		/**
		 * 
		 */
		protected function gather_discount_coeffs_bundles( $product, $parent, $cart_contents ) {

			$quantity = 0;

			foreach( $cart_contents as $valuesInner ) {

				$p = $valuesInner[ 'data' ];

				if( $p instanceof WC_Product_Variation && $p->parent && $p->parent->id == $parent->id ) {
					$quantity += $valuesInner[ 'quantity' ];
					$this->discount_coeffs[$product->variation_id][ 'quantity' ] = $quantity;
				}
			}
			
			return $quantity;
		}

		/**
		 * Filter product price so that the discount is visible during order viewing.
		 *
		 * @param $price
		 * @param $values
		 * @return string
		 */
		public function filter_subtotal_order_price( $price, $values, $order ) {

			if( !$values || !$order ) {
				return $price;
			}

			if( $this->coupon_check() ) {
				return $price;
			}

			if( isset( $values[ 'bundled_by' ] ) )
				return $price;

			$_product = get_product( $values[ 'product_id' ] );
			if( get_post_meta( $values[ 'product_id' ], "_bulkdiscount_enabled", true ) != '' && get_post_meta( $values[ 'product_id' ], "_bulkdiscount_enabled", true ) !== 'yes' ) {
				return $price;
			}

			if( ( get_option( 'woocommerce_t4m_show_on_order_subtotal', 'yes' ) == 'no' ) ) {
				return $price;
			}

			$actual_id = $values[ 'product_id' ];
			if( $_product && $_product instanceof WC_Product_Variable && $values[ 'variation_id' ] ) {
				$actual_id = $values[ 'variation_id' ];
			}

			$discount_coeffs = $this->gather_discount_coeffs_from_order( $order->id );
			if( empty( $discount_coeffs ) ) {
				return $price;
			}

			@$coeff = $discount_coeffs[$actual_id][ 'coeff' ];
			if( !$coeff ) {
				return $price;
			}

			$discount_type = get_post_meta( $order->id, '_woocommerce_t4m_discount_type', true );
			if( ( $discount_type == 'flat' && $coeff == 0 ) || ( $discount_type == '' && $coeff == 1.0 ) ) {
				return $price; // no price modification
			}

			$new_css = esc_attr( get_option( 'woocommerce_t4m_css_new_price', 'color: #4AB915; font-weight: bold;' ) );
			$bulk_info = sprintf( __( 'Incl. %s discount', 'wc_bulk_discount' ), ( $discount_type == 'flat' ? get_woocommerce_currency_symbol() . $coeff : ( round( ( 1 - $coeff ) * 100, 2 ) . "%" ) ) );

			return "<span class='discount-info' title='$bulk_info'>" .
			"<span>$price</span>" .
			"<span class='new-price' style='$new_css'> ($bulk_info)</span></span>";

		}

		/**
		 * Gather discount information from order.
		 *
		 * @param $order_id
		 * @return array
		 */
		protected function gather_discount_coeffs_from_order( $order_id ) {

			$meta = get_post_meta( $order_id, '_woocommerce_t4m_discount_coeffs', true );

			if( ! $meta )
				return null;

			$order_discount_coeffs = json_decode( $meta, true );
			return $order_discount_coeffs;
		}

		/**
		 * Common function that sets prices in the cart
		 *
		 * @param WC_Cart $cart
		 */
		public function the_before_calculator( $cart ) {

			// if there's a coupon, exit
			if( $this->coupon_check() )
				return;

			// if this has already been done, exit
			if( $this->bulk_discount_calculated )
				return;

			// get the coeffs
			$this->gather_discount_coeffs();

			// if there's nothing in the cart
			if( sizeof( $cart->cart_contents ) <= 0 )
				return;

			// go through each item in the cart
			foreach( $cart->cart_contents as $cart_item_key => $values ) {

				// get the product
				$_product = $values[ 'data' ];

				// make sure we can do bulk discounts on this product
				if( get_post_meta( $_product->id, "_bulkdiscount_enabled", true ) != '' && get_post_meta( $_product->id, "_bulkdiscount_enabled", true ) !== 'yes' )
					continue;

				// if it's in a bundle
				if( isset( $values[ 'bundled_by' ] ) ) {

					// get the global
					global $woocommerce;

					// get the bundle id from the cart
					$bundle_id = $woocommerce->cart->cart_contents[ $values[ 'bundled_by' ] ][ 'product_id' ];

					// get the coeff for that id
					$coeff = $this->discount_coeffs[ $bundle_id ][ 'coeff' ];

					// get the quantity of the bundle
					$quantity = $woocommerce->cart->cart_contents[ $values[ 'bundled_by' ] ][ 'quantity' ];

				} else {
					// get the coeff
					$coeff 		= $this->discount_coeffs[$this->get_actual_id( $_product )][ 'coeff' ];
					$quantity 	= $values[ 'quantity' ];
				}

				// if it's a flat discount
				if( ( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) ) {

					$row_base_price = max( 0, $_product->get_price() - ( $coeff / $quantity ) );

				// else it's a percentage
				} else {

					$row_base_price = $_product->get_price() * $coeff;
				}

				// set the row base price
				$values[ 'data' ]->set_price( $row_base_price );
			}

			$this->bulk_discount_calculated = true;
		}

		/**
		 * Hook to woocommerce_before_calculate_totals action.
		 *
		 * @param WC_Cart $cart
		 * @uses 	$this->the_before_calculator
		 */
		public function action_before_calculate( WC_Cart $cart ) {

			$this->the_before_calculator( $cart );
		}

		/**
		 * Hook to  action.
		 *
		 * @param 	$res?
		 * @uses 	$this->the_before_calculator
		 */
		public function filter_before_calculate( $res ) {
			
			// if this has already been done, exit
			if( $this->bulk_discount_calculated )
				return $res;

			// get the global and process the cart
			global $woocommerce;
			$this->the_before_calculator( $woocommerce->cart );

			return $res;
		}

		/**
		 * @param $product
		 * @return int
		 */
		protected function get_actual_id( $product ) {

			if( $product instanceof WC_Product_Variation ) {
				return $product->variation_id;
			} else {
				return $product->id;
			}
		}

		/**
		 * Hook to woocommerce_calculate_totals.
		 * 
		 * Appears to affect appearance, but not calculation
		 *
		 * @param WC_Cart $cart
		 */
		public function action_after_calculate( WC_Cart $cart ) {

			// if there's a coupon, exit
			if( $this->coupon_check() )
				return;

			// if there's nothing in the cart, exit
			if( sizeof( $cart->cart_contents ) <= 0 )
				return;

			// go through each item
			foreach( $cart->cart_contents as $cart_item_key => $values ) {

				// get the product and find out if a bulk discount is enabled
				$_product = $values[ 'data' ];
				if( ! $this->is_bulkdiscount_enabled( $_product->id ) )
					continue;
				
				// 
				$values[ 'data' ]->set_price( $this->discount_coeffs[ $this->get_actual_id( $_product ) ][ 'orig_price' ] );
			}
		}

		/**
		 * Show discount info in cart.
		 */
		public function is_bulkdiscount_enabled( $product_id ) {

			// get the meta field
			$flag = get_post_meta( $product_id, '_bulkdiscount_enabled', true );

			// test it
			if( $flag != '' && $flag !== 'yes' )
				return false;

			// return
			return true;
		}

		/**
		 * Show discount info in cart.
		 */
		public function before_cart_table() {

			if( get_option( 'woocommerce_t4m_cart_info' ) != '' ) {
				echo "<div class='cart-show-discounts'>";
				echo get_option( 'woocommerce_t4m_cart_info' );
				echo "</div>";
			}
		}

		/**
		 * Hook to woocommerce_cart_product_subtotal filter.
		 *
		 * Appears to only change what is displayed. 
		 *
		 * @param $subtotal
		 * @param $_product
		 * @param $quantity
		 * @param WC_Cart $cart
		 * @return string
		 */
		public function filter_cart_product_subtotal( $subtotal, $_product, $quantity ) {

			// no product or quantity, return
			if( ! $_product || ! $quantity )
				return $subtotal;

			// there's a coupon, return
			if( $this->coupon_check() )
				return $subtotal;

			// if this doesn't have a bulk discount, return
			if( ! $this->is_bulkdiscount_enabled( $_product->id ) )
				return $subtotal;

			// get the coefs
			$coeff = $this->discount_coeffs[$this->get_actual_id( $_product )][ 'coeff' ];
			if( ( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) ) {
				$newsubtotal = woocommerce_price( max( 0, ( $_product->get_price() * $quantity ) - $coeff ) );
			} else {
				$newsubtotal = woocommerce_price( $_product->get_price() * $quantity * $coeff );
			}

			return $newsubtotal;
		}

		/**
		 * Store discount info in order as well
		 *
		 * @param $order_id
		 */
		public function order_update_meta( $order_id ) {

			update_post_meta( $order_id, "_woocommerce_t4m_discount_type", get_option( 'woocommerce_t4m_discount_type', '' ) );
			update_post_meta( $order_id, "_woocommerce_t4m_discount_coeffs", json_encode( $this->discount_coeffs ) );

		}

		/**
		 * Display discount information in Product Detail.
		 */
		public function single_product_summary() {

			global $thepostid, $post;
			if( !$thepostid ) $thepostid = $post->ID;

			echo "<div class='productinfo-show-discounts'>";
			echo get_post_meta( $thepostid, '_bulkdiscount_text_info', true );
			echo "</div>";
		}

		/**
		 * Add entry to Product Settings.
		 */
		public function action_product_write_panel_tabs() {

			$style = '';

			if( version_compare( WOOCOMMERCE_VERSION, "2.1.0" ) >= 0 ) {
				$style = 'style = "padding: 10px !important"';
			}

			echo '<li class="bulkdiscount_tab bulkdiscount_options"><a href="#bulkdiscount_product_data" '.$style.'>' . __( 'Bulk Discount', 'wc_bulk_discount' ) . '</a></li>';
		}

		/**
		 * Add entry content to Product Settings.
		 */
		public function action_product_write_panels() {

			global $thepostid, $post;

			if( ! $thepostid ) $thepostid = $post->ID;
			?>
			<script type="text/javascript">
				jQuery( document ).ready( function () {
					var e = jQuery( '#bulkdiscount_product_data' );
					<?php
					for($i = 1; $i <= 6; $i++) :
					?>
					e.find( '.block<?php echo $i; ?>' ).hide();
					e.find( '.options_group<?php echo max($i, 2); ?>' ).hide();
					e.find( '#add_discount_line<?php echo max($i, 2); ?>' ).hide();
					e.find( '#add_discount_line<?php echo $i; ?>' ).click( function () {
						if( <?php echo $i; ?> == 1 || ( e.find( '#_bulkdiscount_quantity_<?php echo max($i-1, 1); ?>' ).val() != '' &&
							<?php if( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) : ?>
							e.find( '#_bulkdiscount_discount_flat_<?php echo max($i-1, 1); ?>' ).val() != ''
						<?php else: ?>
						e.find( '#_bulkdiscount_discount_<?php echo max($i-1, 1); ?>' ).val() != ''
						<?php endif; ?>
						) )
						{
							e.find( '.block<?php echo $i; ?>' ).show( 400 );
							e.find( '.options_group<?php echo min($i+1, 6); ?>' ).show( 400 );
							e.find( '#add_discount_line<?php echo min($i+1, 5); ?>' ).show( 400 );
							e.find( '#add_discount_line<?php echo $i; ?>' ).hide( 400 );
							e.find( '#delete_discount_line<?php echo min($i+1, 6); ?>' ).show( 400 );
							e.find( '#delete_discount_line<?php echo $i; ?>' ).hide( 400 );
						}
						else
						{
							alert( '<?php _e( 'Please fill in the current line before adding new line.', 'wc_bulk_discount' ); ?>' );
						}
					} );
					e.find( '#delete_discount_line<?php echo max($i, 1); ?>' ).hide();
					e.find( '#delete_discount_line<?php echo $i; ?>' ).click( function () {
						e.find( '.block<?php echo max($i-1, 1); ?>' ).hide( 400 );
						e.find( '.options_group<?php echo min($i, 6); ?>' ).hide( 400 );
						e.find( '#add_discount_line<?php echo min($i, 5); ?>' ).hide( 400 );
						e.find( '#add_discount_line<?php echo max($i-1, 1); ?>' ).show( 400 );
						e.find( '#delete_discount_line<?php echo min($i, 6); ?>' ).hide( 400 );
						e.find( '#delete_discount_line<?php echo max($i-1, 2); ?>' ).show( 400 );
						e.find( '#_bulkdiscount_quantity_<?php echo max($i-1, 1); ?>' ).val( '' );
						<?php
							if( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) :
						?>
						e.find( '#_bulkdiscount_discount_flat_<?php echo max($i-1, 1); ?>' ).val( '' );
						<?php else: ?>
						e.find( '#_bulkdiscount_discount_<?php echo max($i-1, 1); ?>' ).val( '' );
						<?php endif; ?>
					} );
					<?php
					endfor;
					for ($i = 1, $j = 2; $i <= 5; $i++, $j++) {
						$cnt = 1;
						if(get_post_meta($thepostid, "_bulkdiscount_quantity_$i", true) || get_post_meta($thepostid, "_bulkdiscount_quantity_$j", true)) {
							?>
					e.find( '.block<?php echo $i; ?>' ).show();
					e.find( '.options_group<?php echo $i; ?>' ).show();
					e.find( '#add_discount_line<?php echo $i; ?>' ).hide();
					e.find( '#delete_discount_line<?php echo $i; ?>' ).hide();
					e.find( '.options_group<?php echo min($i+1,6); ?>' ).show();
					e.find( '#add_discount_line<?php echo min($i+1,6); ?>' ).show();
					e.find( '#delete_discount_line<?php echo min($i+1,6); ?>' ).show();
					<?php
					$cnt++;
				}
			}
			if($cnt >= 6) {
				?>e.find( '#add_discount_line6' ).show();
					<?php
			}
			?>
				} );
			</script>

			<div id="bulkdiscount_product_data" class="panel woocommerce_options_panel">

				<div class="options_group">
					<?php
					woocommerce_wp_checkbox( array( 'id' => '_bulkdiscount_enabled', 'value' => get_post_meta( $thepostid, '_bulkdiscount_enabled', true ) ? get_post_meta( $thepostid, '_bulkdiscount_enabled', true ) : 'yes', 'label' => __( 'Bulk Discount enabled', 'wc_bulk_discount' ) ) );
					woocommerce_wp_textarea_input( array( 'id' => "_bulkdiscount_text_info", 'label' => __( 'Bulk discount special offer text in product description', 'wc_bulk_discount' ), 'description' => __( 'Optionally enter bulk discount information that will be visible on the product page.', 'wc_bulk_discount' ), 'desc_tip' => 'yes', 'class' => 'fullWidth' ) );
					?>
				</div>

				<?php
				for ( $i = 1;
				      $i <= 5;
				      $i++ ) :
					?>

					<div class="options_group<?php echo $i; ?>">
						<a id="add_discount_line<?php echo $i; ?>" class="button-secondary"
						   href="#block<?php echo $i; ?>"><?php _e( 'Add discount line', 'wc_bulk_discount' ); ?></a>
						<a id="delete_discount_line<?php echo $i; ?>" class="button-secondary"
						   href="#block<?php echo $i; ?>"><?php _e( 'Remove last discount line', 'wc_bulk_discount' ); ?></a>

						<div class="block<?php echo $i; ?> <?php echo ( $i % 2 == 0 ) ? 'even' : 'odd' ?>">
							<?php
							woocommerce_wp_text_input( array( 'id' => "_bulkdiscount_quantity_$i", 'label' => __( 'Quantity (min.)', 'wc_bulk_discount' ), 'type' => 'number', 'description' => __( 'Enter the minimal quantity for which the discount applies.', 'wc_bulk_discount' ), 'custom_attributes' => array(
								'step' => '1',
								'min' => '1'
							) ) );
							if( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) {
								woocommerce_wp_text_input( array( 'id' => "_bulkdiscount_discount_flat_$i", 'type' => 'number', 'label' => sprintf( __( 'Discount (%s)', 'wc_bulk_discount' ), get_woocommerce_currency_symbol() ), 'description' => sprintf( __( 'Enter the flat discount in %s.', 'wc_bulk_discount' ), get_woocommerce_currency_symbol() ), 'custom_attributes' => array(
									'step' => 'any',
									'min' => '0'
								) ) );
							} else {
								woocommerce_wp_text_input( array( 'id' => "_bulkdiscount_discount_$i", 'type' => 'number', 'label' => __( 'Discount (%)', 'wc_bulk_discount' ), 'description' => __( 'Enter the discount in percents (Allowed values: 0 to 100).', 'wc_bulk_discount' ), 'custom_attributes' => array(
									'step' => 'any',
									'min' => '0',
									'max' => '100'
								) ) );
							}
							?>
						</div>
					</div>

				<?php
				endfor;
				?>

				<div class="options_group6">
					<a id="delete_discount_line6" class="button-secondary"
					   href="#block6"><?php _e( 'Remove last discount line', 'wc_bulk_discount' ); ?></a>
				</div>

				<br/>

			</div>

		<?php
		}

		/**
		 * Enqueue frontend dependencies.
		 */
		public function action_enqueue_dependencies() {

			wp_register_style( 'woocommercebulkdiscount-style', plugins_url( 'css/style.css', __FILE__ ) );
			wp_enqueue_style( 'woocommercebulkdiscount-style' );
			wp_enqueue_script( 'jquery' );

		}

		/**
		 * Enqueue backend dependencies.
		 */
		public function action_enqueue_dependencies_admin() {

			wp_register_style( 'woocommercebulkdiscount-style-admin', plugins_url( 'css/admin.css', __FILE__ ) );
			wp_enqueue_style( 'woocommercebulkdiscount-style-admin' );
			wp_enqueue_script( 'jquery' );

		}

		/**
		 * Updating post meta.
		 *
		 * @param $post_id
		 */
		public function action_process_meta( $post_id ) {

			if( isset( $_POST[ '_bulkdiscount_text_info' ] ) ) update_post_meta( $post_id, '_bulkdiscount_text_info', stripslashes( $_POST[ '_bulkdiscount_text_info' ] ) );

			if( isset( $_POST[ '_bulkdiscount_enabled' ] ) && $_POST[ '_bulkdiscount_enabled' ] == 'yes' ) {
				update_post_meta( $post_id, '_bulkdiscount_enabled', stripslashes( $_POST[ '_bulkdiscount_enabled' ] ) );
			} else {
				update_post_meta( $post_id, '_bulkdiscount_enabled', stripslashes( 'no' ) );
			}

			for ( $i = 1; $i <= 5; $i++ ) {
				if( isset( $_POST["_bulkdiscount_quantity_$i"] ) ) update_post_meta( $post_id, "_bulkdiscount_quantity_$i", stripslashes( $_POST["_bulkdiscount_quantity_$i"] ) );
				if( ( get_option( 'woocommerce_t4m_discount_type', '' ) == 'flat' ) ) {
					if( isset( $_POST["_bulkdiscount_discount_flat_$i"] ) ) update_post_meta( $post_id, "_bulkdiscount_discount_flat_$i", stripslashes( $_POST["_bulkdiscount_discount_flat_$i"] ) );
				} else {
					if( isset( $_POST["_bulkdiscount_discount_$i"] ) ) update_post_meta( $post_id, "_bulkdiscount_discount_$i", stripslashes( $_POST["_bulkdiscount_discount_$i"] ) );
				}
			}

		}

		/**
		 * @access public
		 * @return void
		 */
		public function add_tab() {
		
			$settings_slug = 'woocommerce';
		
			if( version_compare( WOOCOMMERCE_VERSION, "2.1.0" ) >= 0 ) {
				
				$settings_slug = 'wc-settings';			
				
			}

			foreach( $this->settings_tabs as $name => $label ) {
				$class = 'nav-tab';
				if( $this->current_tab == $name )
					$class .= ' nav-tab-active';
				echo '<a href="' . admin_url( 'admin.php?page=' . $settings_slug . '&tab=' . $name ) . '" class="' . $class . '">' . $label . '</a>';
			}

		}

		/**
		 * @access public
		 * @return void
		 */
		public function settings_tab_action() {

			global $woocommerce_settings;

			// Determine the current tab in effect.
			$current_tab = $this->get_tab_in_view( current_filter(), 'woocommerce_settings_tabs_' );

			do_action( 'woocommerce_bulk_discount_settings' );

			// Display settings for this tab (make sure to add the settings to the tab).
			woocommerce_admin_fields( $woocommerce_settings[$current_tab] );

		}

		/**
		 * Save settings in a single field in the database for each tab's fields (one field per tab).
		 */
		public function save_settings() {

			global $woocommerce_settings;

			// Make sure our settings fields are recognised.
			$this->add_settings_fields();

			$current_tab = $this->get_tab_in_view( current_filter(), 'woocommerce_update_options_' );
			woocommerce_update_options( $woocommerce_settings[$current_tab] );

		}

		/**
		 * Get the tab current in view/processing.
		 */
		public function get_tab_in_view( $current_filter, $filter_base ) {

			return str_replace( $filter_base, '', $current_filter );

		}


		/**
		 * Add settings fields for each tab.
		 */
		public function add_settings_fields() {
			global $woocommerce_settings;

			// Load the prepared form fields.
			$this->init_form_fields();

			if( is_array( $this->fields ) )
				foreach( $this->fields as $k => $v )
					$woocommerce_settings[$k] = $v;
		}

		/**
		 * Prepare form fields to be used in the various tabs.
		 */
		public function init_form_fields() {
			global $woocommerce;

			// Define settings
			$this->fields[ 'bulk_discount' ] = array(

				array( 
					'name' 	=> __( 'Bulk Discount', 'wc_bulk_discount' ),
					'type' 	=> 'title',
					'desc' 	=> __( 'The following options are specific to product bulk discount.', 'wc_bulk_discount' ) . '<br /><br/><strong><i>' . __( 'After changing the settings, it is recommended to clear all sessions in WooCommerce &gt; System Status &gt; Tools.', 'wc_bulk_discount' ) . '</i></strong>',
					'id' 	=> 't4m_bulk_discounts_options' ),

				array(
					'name' 		=> __( 'Bulk Discount globally enabled', 'wc_bulk_discount' ),
					'id' 		=> 'woocommerce_t4m_enable_bulk_discounts',
					'desc' 		=> __( '', 'wc_bulk_discount' ),
					'std' 		=> 'yes',
					'type' 		=> 'checkbox',
					'default'	=> 'yes'
				),

				array(
					'title' 	=> __( 'Discount Type', 'wc_bulk_discount' ),
					'id' 		=> 'woocommerce_t4m_discount_type',
					'desc' 		=> sprintf( __( 'Select the type of discount. Percentage Discount deducts amount of %% from price while Flat Discount deducts fixed amount in %s', 'wc_bulk_discount' ), get_woocommerce_currency_symbol() ),
					'desc_tip'	=> true,
					'std'		=> 'yes',
					'type'		=> 'select',
					'css'		=> 'min-width:200px;',
					'class'		=> 'chosen_select',
					'options'	=> array(
						'' 		=> __( 'Percentage Discount', 'wc_bulk_discount' ),
						'flat' 	=> __( 'Flat Discount', 'wc_bulk_discount' )
					)
				),

				array(
					'name' 		=> __( 'Treat product variations separately', 'wc_bulk_discount' ),
					'id' 		=> 'woocommerce_t4m_variations_separate',
					'desc' 		=> __( 'You need to have this option unchecked to apply discounts to variations by shared quantity.', 'wc_bulk_discount' ),
					'std' 		=> 'yes',
					'type' 		=> 'checkbox',
					'default'	=> 'yes'
				),

				array(
					'name' 		=> __( 'Remove any bulk discounts if a coupon code is applied', 'wc_bulk_discount' ),
					'id' 		=> 'woocommerce_t4m_remove_discount_on_coupon',
					'std' 		=> 'yes',
					'type' 		=> 'checkbox',
					'default'	=> 'yes'
				),

				array(
					'name' 		=> __( 'Show discount information next to cart item price', 'wc_bulk_discount' ),
					'id' 		=> 'woocommerce_t4m_show_on_item',
					'desc' 		=> __( 'Applies only to percentage discount.', 'wc_bulk_discount' ),
					'std' 		=> 'yes',
					'type' 		=> 'checkbox',
					'default'	=> 'yes'
				),

				array(
					'name'		=> __( 'Show discount information next to item subtotal price', 'wc_bulk_discount' ),
					'id'		=> 'woocommerce_t4m_show_on_subtotal',
					'std'		=> 'yes',
					'type'		=> 'checkbox',
					'default'	=> 'yes'
				),

				array(
					'name'		=> __( 'Show discount information next to item subtotal price in order history', 'wc_bulk_discount' ),
					'id'		=> 'woocommerce_t4m_show_on_order_subtotal',
					'desc'		=> __( 'Includes showing discount in order e-mails and invoices.', 'wc_bulk_discount' ),
					'std'		=> 'yes',
					'type'		=> 'checkbox',
					'default'	=> 'yes'
				),

				array(
					'name'	=> __( 'Optionally enter information about discounts visible on cart page.', 'wc_bulk_discount' ),
					'id'	=> 'woocommerce_t4m_cart_info',
					'type'	=> 'textarea',
					'css'	=> 'width:100%; height: 75px;'
				),

				array(
					'name'		=> __( 'Optionally change the CSS for old price on cart before discounting.', 'wc_bulk_discount' ),
					'id' 		=> 'woocommerce_t4m_css_old_price',
					'type' 		=> 'textarea',
					'css' 		=> 'width:100%;',
					'default'	=> 'color: #777; text-decoration: line-through; margin-right: 4px;'
				),

				array(
					'name'		=> __( 'Optionally change the CSS for new price on cart after discounting.', 'wc_bulk_discount' ),
					'id'		=> 'woocommerce_t4m_css_new_price',
					'type'		=> 'textarea',
					'css'		=> 'width:100%;',
					'default'	=> 'color: #4AB915; font-weight: bold;'
				),

				array( 
					'type'	=> 'sectionend',
					'id'	=> 't4m_bulk_discounts_options'
				),

				// array(
				// 	'desc' 	=> 'If you find the WooCommerce Bulk Discount extension useful, please rate it <a target="_blank" href="http://wordpress.org/support/view/plugin-reviews/woocommerce-bulk-discount#postform">&#9733;&#9733;&#9733;&#9733;&#9733;</a>.',
				// 	'id'	=> 'woocommerce_t4m_bulk_discount_notice_text',
				// 	'type'	=> 'title'
				// ),

				// array( 
				// 	'type'	=> 'sectionend',
				// 	'id' 	=> 'woocommerce_t4m_bulk_discount_notice_text'
				// ),

			); // End settings

			// do we have Product Bundles?
			if( class_exists( 'WC_Bundles' ) ) {

				$bundles_settings = array(

					array(
						'name' 	=> __( 'Product Bundles', 'wc_bulk_discount' ),
						'type' 	=> 'title',
						'desc' 	=> __( 'The following options are specific to Product Bundles.', 'wc_bulk_discount' ) . '<br /><br/><strong><i>' . __( 'After changing the settings, it is recommended to clear all sessions in WooCommerce &gt; System Status &gt; Tools.', 'wc_bulk_discount' ) . '</i></strong>',
						'id' 	=> 't4m_bulk_discounts_bundles_options'
					),

					array(
						'name' 		=> __( 'Consolidate bundled products', 'wc_bulk_discount' ),
						'id' 		=> 'woocommerce_t4m_bundles_separate',
						'desc' 		=> __( 'When checked, products that are in bundles will be considered for discounts.', 'wc_bulk_discount' ),
						'std' 		=> 'yes',
						'type' 		=> 'checkbox',
						'default'	=> 'yes'
					),

					array( 
						'type'	=> 'sectionend',
						'id'	=> 't4m_bulk_discounts_bundles_options'
					),
				);

				$this->fields[ 'bulk_discount' ] = array_merge( $this->fields[ 'bulk_discount' ], $bundles_settings );
			}

			// apply filters
			$this->fields[ 'bulk_discount' ] = apply_filters( 'woocommerce_bulk_discount_settings_fields', $this->fields[ 'bulk_discount' ] );

			$js = "
					jQuery('#woocommerce_t4m_enable_bulk_discounts').change(function() {

						jQuery('#woocommerce_t4m_cart_info, #woocommerce_t4m_variations_separate, #woocommerce_t4m_discount_type, #woocommerce_t4m_css_old_price, #woocommerce_t4m_css_new_price, #woocommerce_t4m_show_on_item, #woocommerce_t4m_show_on_subtotal, #woocommerce_t4m_show_on_order_subtotal').closest('tr').hide();

						if( jQuery(this).attr('checked') ) {
							jQuery('#woocommerce_t4m_cart_info').closest('tr').show();
							jQuery('#woocommerce_t4m_variations_separate').closest('tr').show();
							jQuery('#woocommerce_t4m_discount_type').closest('tr').show();
							jQuery('#woocommerce_t4m_css_old_price').closest('tr').show();
							jQuery('#woocommerce_t4m_css_new_price').closest('tr').show();
							jQuery('#woocommerce_t4m_show_on_item').closest('tr').show();
							jQuery('#woocommerce_t4m_show_on_subtotal').closest('tr').show();
							jQuery('#woocommerce_t4m_show_on_order_subtotal').closest('tr').show();
						}

					}).change();

				";

			$this->run_js( $js );

		}

		/**
		 * Includes inline JavaScript.
		 *
		 * @param $js
		 */
		protected function run_js( $js ) {

			global $woocommerce;

			if( function_exists( 'wc_enqueue_js' ) ) {
				wc_enqueue_js( $js );
			} else {
				$woocommerce->add_inline_js( $js );
			}

		}

		/**
         * @return bool
		 */
		protected function coupon_check() {

			global $woocommerce;

			if( get_option( 'woocommerce_t4m_remove_discount_on_coupon', 'yes' ) == 'no' ) return false;
			return !( empty( $woocommerce->cart->applied_coupons ) );
		}

	}

	$woo_bulk_discounts_plugin = new WooCommerce_Bulk_Discounts();
}