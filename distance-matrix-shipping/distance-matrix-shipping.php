<?php

/**
 * Plugin Name: Distance Matrix Shipping Method.
 * Description: Create a woocommerce custom shipping method plugin using Distance Matrix API Google
 * 
 * @woocomerce-version 4.1.0
 *
 * @version 1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
  die('security by preventing any direct access to your plugin file');
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
  function distance_matrix_shipping_method()  {
    if (!class_exists('MT_Distance_Matrix_Rate')) {
      class MT_Distance_Matrix_Rate extends WC_Shipping_Method{

        /**
         * Cost passed to [fee] shortcode.
         * 
         * @var string Cost.
         */
        protected $fee_cost = '';
      
        /**
         * Constructor.
         * 
         * @param int $instance_id Shipping method instance ID.
         */
        public function __construct( $instance_id = 0 ) {
          $this->id = 'distance_matrix_shipping';
          $this->instance_id = absint( $instance_id );
          $this->method_title = __( 'Distance Matrix', 'woocommerce' );
          $this->method_description = __( 'Permite o cálculo do custo de envio baseado na distancia.', 'woocommerce' );
          $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
          );
          $this->init();
        }
      
        /**
         * Init user set variables.
         */
        public function init() {
          $this->instance_form_fields = include 'includes/settings-distance-matrix.php';
          $this->title                = $this->get_option( 'title' );
          $this->tax_status           = $this->get_option( 'tax_status' );
          $this->cost_per_distance    = $this->get_option( 'cost_per_distance' );
          $this->api_key              = $this->get_option( 'api_key' );
          $this->owner_postcode       = $this->get_option( 'owner_postcode' );
          $this->minimum_cost         = $this->get_option( 'minimum_cost' );
          $this->type                 = $this->get_option( 'type', 'class' );
        }
      
        /**
         * Evaluate a cost from a sum/string.
         * 
         * @param string $sum Sum os shipping.
         * @param array $args Args, must contain 'cost' and 'qty' keys. Having 'array()' as default is for back compat reasons;
         * @return string 
         */
        protected function evaluate_cost( $sum, $args = array() ){
          // add warning for subclasses.
          if ( !is_array( $args ) || !array_key_exists( 'qty', $args) || !array_key_exists( 'cost', $args )){
            wc_doing_it_wrong( __FUNCTION__, '$args must contain "cost" and "qty" keys.', '4.0.1');
          }
      
          include_once WC()->plugin_path() . '/includes/libraries/class-wc-eval-math.php';
      
          // Allow 3rd parties to process shipping cost arguments.
          $args = apply_filters( 'woocommerce_evaluate_shipping_cost_args', $args, $sum, $this);
          $locale = localeconv();
          $decimals = array( wc_get_price_decimal_separator(), $locale['decimal_point'], $locale['mon_decimal_point'], ',');
          $this->fee_cost = $args['cost'];
      
          // Expand shortcodes.
          add_shortcode( 'fee', array( $this, 'fee') );
      
          $sum = do_shortcode (
            str_replace(
              array(
                '[qty]',
                '[cost]',
              ),
              array(
                $args['qty'],
                $args['cost'],
              ),
              $sum
            )
          );
      
          remove_shortcode( 'fee', array( $this, 'fee') );
      
          // Remove whitespace from string.
          $sum = preg_replace( '/\s+/', '', $sum );
      
          // Remove locale from string.
          $sum = str_replace( $decimals, '.', $sum );
      
          // Trim invalid start/end characters.
          $sum = rtrim( ltrim( $sum, "\t\n\r\0\x0B+*/" ), "\t\n\r\0\x0B+-*/" );
      
          // Do the math.
          return $sum ? WC_Eval_Math::evaluate( $sum ) : 0;
        }
      
        /**
        * Work out fee (shortcode).
        *
        * @param  array $atts Attributes.
        * @return string
        */
        public function fee( $atts ) {
          $atts = shortcode_atts(
            array(
              'percent' => '',
              'min_fee' => '',
              'max_fee' => '',
            ),
            $atts,
            'fee'
          );
      
          $calculated_fee = 0;
      
          if ( $atts['percent'] ) {
            $calculated_fee = $this->fee_cost * ( floatval( $atts['percent'] ) / 100 );
          }
      
          if ( $atts['min_fee'] && $calculated_fee < $atts['min_fee'] ) {
            $calculated_fee = $atts['min_fee'];
          }
      
          if ( $atts['max_fee'] && $calculated_fee > $atts['max_fee'] ) {
            $calculated_fee = $atts['max_fee'];
          }
      
          return $calculated_fee;
        }
        
        function debug_to_console( $data ) {
          $output = $data;
          if ( is_array( $output ) )
              $output = json_encode( $output );
      
          echo "<script>console.log('Debug Objects: " . $output . "' );</script>";
        }
      
        /**
         * Sanitize postcode.
         * 
         * @param string $postcode.
         * @return string $sanitized_postcode.
         */
        function sanitize_postcode( $postcode ){
          $postcode_to_string = strval( $postcode );
          $trim_postcode = trim( $postcode );
          $replace_non_numbers = preg_replace('/([a-zA-Z]|\W|_)/', '', $trim_postcode);
      
          if ( strlen( $replace_non_numbers ) == 8 ){
            return $replace_non_numbers;
          } else {
            return 0;
          }
        }
        
        /**
         * Realize the communication with Distance Matrix API Google.
         * 
         * @param string $postcode Postcode shipping
         * @return array $data Data returned from API
         */
        function get_distance_matrix_data( $postcode ){
          $postcode = $this->sanitize_postcode( $postcode );
      
          if( $postcode ){
            $data = file_get_contents('https://maps.googleapis.com/maps/api/distancematrix/json?units=imperial&origins=' . $this->owner_postcode . '&destinations=' . $postcode . '&key=' . $this->api_key );
            //AIzaSyCPpHLDvL363M1EwiuSPNuvWQdwl3HxrHw
      
            return json_decode( $data );
          }
      
          return '';
        }
      
        /**
         * Get the cost distance calculated from owner_postcode to shipping_postcode
         * 
         * @param string $postcode.
         * @return string $cost.
         */
        function get_cost( $postcode ){
          $data = $this->get_distance_matrix_data( $postcode );
          if ( $data ){
            $status = $data->rows[0]->elements[0]->status;
            if( $status == "OK" ){
              $distance = $data->rows[0]->elements[0]->distance->value;
              $cost = (int)( $distance / 1000 ) *  $this->cost_per_distance;
              return $cost . ",00";
            }
          }
      
          return '0,00';
        }
      
        /**
         * Calculate the shipping costs.
         *
         * @param array $package Package of items from cart.
         */
        public function calculate_shipping( $package = array() ) {
          $rate = array(
            'id'      => $this->get_rate_id(),
            'label'   => $this->title,
            'cost'    => 0,
            'package' => $package,
          );
      
      
          // Calculate the costs.
          $has_costs = false; // True when a cost is set. False if all costs are blank strings.
          $postcode = $package['destination']['postcode'];
          $contents_cost = $package['contents_cost'];

          $cost = ( $contents_cost < $this->minimum_cost ) ? '' : $this->get_cost( $postcode );
      
          if ( '' !== $cost ) {
            $has_costs    = true;
            $rate['cost'] = $this->evaluate_cost(
              $cost,
              array(
                'qty'  => $this->get_package_item_qty( $package ),
                'cost' => $package['contents_cost'],
              )
            );
          }
      
          // Add shipping class costs.
          $shipping_classes = WC()->shipping()->get_shipping_classes();
      
          if ( ! empty( $shipping_classes ) ) {
            $found_shipping_classes = $this->find_shipping_classes( $package );
            $highest_class_cost     = 0;
      
            foreach ( $found_shipping_classes as $shipping_class => $products ) {
              // Also handles BW compatibility when slugs were used instead of ids.
              $shipping_class_term = get_term_by( 'slug', $shipping_class, 'product_shipping_class' );
              $class_cost_string   = $shipping_class_term && $shipping_class_term->term_id ? $this->get_option( 'class_cost_' . $shipping_class_term->term_id, $this->get_option( 'class_cost_' . $shipping_class, '' ) ) : $this->get_option( 'no_class_cost', '' );
      
              if ( '' === $class_cost_string ) {
                continue;
              }
      
              $has_costs  = true;
              $class_cost = $this->evaluate_cost(
                $class_cost_string,
                array(
                  'qty'  => array_sum( wp_list_pluck( $products, 'quantity' ) ),
                  'cost' => array_sum( wp_list_pluck( $products, 'line_total' ) ),
                )
              );
      
              if ( 'class' === $this->type ) {
                $rate['cost'] += $class_cost;
              } else {
                $highest_class_cost = $class_cost > $highest_class_cost ? $class_cost : $highest_class_cost;
              }
            }
      
            if ( 'order' === $this->type && $highest_class_cost ) {
              $rate['cost'] += $highest_class_cost;
            }
          }
      
          if ( $has_costs ) {
            $this->add_rate( $rate );
          }
        }
        
        /**
         * Get items in package.
         *
         * @param  array $package Package of items from cart.
         * @return int
         */
        public function get_package_item_qty( $package ) {
          $total_quantity = 0;
          foreach ( $package['contents'] as $item_id => $values ) {
            if ( $values['quantity'] > 0 && $values['data']->needs_shipping() ) {
              $total_quantity += $values['quantity'];
            }
          }
          return $total_quantity;
        }
      
        /**
         * Finds and returns shipping classes and the products with said class.
         *
         * @param mixed $package Package of items from cart.
         * @return array
         */
        public function find_shipping_classes( $package ) {
          $found_shipping_classes = array();
      
          foreach ( $package['contents'] as $item_id => $values ) {
            if ( $values['data']->needs_shipping() ) {
              $found_class = $values['data']->get_shipping_class();
      
              if ( ! isset( $found_shipping_classes[ $found_class ] ) ) {
                $found_shipping_classes[ $found_class ] = array();
              }
      
              $found_shipping_classes[ $found_class ][ $item_id ] = $values;
            }
          }
      
          return $found_shipping_classes;
        }
      
        /**
         * Sanitize the cost field.
         *
         * @since 3.4.0
         * @param string $value Unsanitized value.
         * @throws Exception Last error triggered.
         * @return string
         */
        public function sanitize_cost( $value ) {
          $value = is_null( $value ) ? '' : $value;
          $value = wp_kses_post( trim( wp_unslash( $value ) ) );
          $value = str_replace( array( get_woocommerce_currency_symbol(), html_entity_decode( get_woocommerce_currency_symbol() ) ), '', $value );
          // Thrown an error on the front end if the evaluate_cost will fail.
          $dummy_cost = $this->evaluate_cost(
            $value,
            array(
              'cost' => 1,
              'qty'  => 1,
            )
          );
          if ( false === $dummy_cost ) {
            throw new Exception( WC_Eval_Math::$last_error );
          }
          return $value;
        }
      }
    }
  }

  add_action( 'woocommerce_shipping_init', 'distance_matrix_shipping_method' );

	function add_your_shipping_method( $methods ) {
		$methods['distance_matrix_shipping'] = 'MT_Distance_Matrix_Rate';
		return $methods;
	}

	add_filter( 'woocommerce_shipping_methods', 'add_your_shipping_method' );
}

?>