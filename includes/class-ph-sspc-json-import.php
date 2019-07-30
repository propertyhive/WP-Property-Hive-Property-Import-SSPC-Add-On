<?php
/**
 * WordPress Importer class for managing the import process of an SSPC JSON file
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {

class PH_SSPC_JSON_Import extends WP_Importer {

	/**
	 * @var string
	 */
	private $target_file;

	/**
	 * @var array
	 */
	private $properties;

	/**
	 * @var array
	 */
	private $errors;

	/**
	 * @var array
	 */
	private $mappings;

	/**
	 * @var array
	 */
	private $import_log;

	/**
	 * @var int
	 */
	private $instance_id;

	public function __construct( $target_file = '', $instance_id = '' ) 
	{
		$this->target_file = $target_file;
		$this->instance_id = $instance_id;

		if ( isset($_GET['custom_property_import_cron']) )
	    {
	    	$current_user = wp_get_current_user();

	    	$this->add_log("Executed manually by " . ( ( isset($current_user->display_name) ) ? $current_user->display_name : '' ) );
	    }
	}

	public function parse()
	{
		$this->properties = array(); // Reset properties in the event we're importing multiple files
		
		$contents = file_get_contents($this->target_file);
		$json = json_decode( $contents );

		if ($json !== FALSE)
		{
			$this->add_log("Parsing properties");
			
            $properties_imported = 0;
            
			foreach ($json->property as $property)
			{
                $this->properties[] = $property;
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->add_error( 'Failed to parse JSON file. Possibly invalid JSON' );

        	return false;
        }

        return true;
	}

	// Only used on manual import to validate and build custom fields used
	public function pre_test()
	{
		$this->mappings = array(); // Reset mappings in the event we're importing multiple files

		$passed_properties = 0;
		$failed_properties = 0;

		foreach ($this->properties as $property)
		{
			$passed = true;

			// validation here...

			if ( $passed )
			{
				++$passed_properties;
			}
			else
			{
				++$failed_properties;
			}

			// Build mappings
			if ( isset($property->availability) )
			{
				if ( !isset($this->mappings['availability']) ) { $this->mappings['availability'] = array(); }

				$this->mappings['availability'][$property->availability] = '';
			}

			if ( isset($property->propertyType) && isset($property->propertyStyle) )
			{
				if ( !isset($this->mappings['property_type']) ) { $this->mappings['property_type'] = array(); }

				$this->mappings['property_type'][$property->propertyType . ' - ' . $property->propertyStyle] = '';
			}

			if ( isset($property->priceQualifier) )
			{
				if ( !isset($this->mappings['price_qualifier']) ) { $this->mappings['price_qualifier'] = array(); }

				$this->mappings['price_qualifier'][$property->priceQualifier] = '';
			}

			if ( isset($property->propertyTenure) )
			{
				if ( !isset($this->mappings['tenure']) ) { $this->mappings['tenure'] = array(); }

				$this->mappings['tenure'][$property->propertyTenure] = '';
			}

			// Furnished not currently a field in the Juvo XML
			if ( isset($property->furnished) )
			{
				if ( !isset($this->mappings['furnished']) ) { $this->mappings['furnished'] = array(); }

				$this->mappings['furnished'][$property->furnished] = '';
			}

			if ( isset($property->branchID) )
			{
				if ( !isset($this->mappings['office']) ) { $this->mappings['office'] = array(); }

				$this->mappings['office'][$property->branchID] = '';
			}

			// Sort mappings
			foreach ( $this->mappings as $custom_field_name => $custom_field_values )
			{
				ksort( $this->mappings[$custom_field_name], SORT_NUMERIC );
			}
		}

		return array( $passed_properties, $failed_properties );
	}

	public function import( $import_id = '' )
	{
		global $wpdb;

		$imported_ref_key = ( ( $import_id != '' ) ? '_imported_ref_' . $import_id : '_imported_ref' );

		$options = get_option( 'propertyhive_property_import' );
		if (isset($options[$import_id]))
		{
			$options = $options[$import_id];
		}
		else
		{
			$options = array();
		}

		$this->add_log( 'Starting import' );

		$this->import_start();

		if ( !function_exists('media_handle_upload') ) {
			require_once(ABSPATH . "wp-admin" . '/includes/image.php');
			require_once(ABSPATH . "wp-admin" . '/includes/file.php');
			require_once(ABSPATH . "wp-admin" . '/includes/media.php');
		}

		// Get primary office in the event office mappings weren't set
		$primary_office_id = '';
		$args = array(
            'post_type' => 'office',
            'nopaging' => true
        );
        $office_query = new WP_Query($args);
        
        if ($office_query->have_posts())
        {
            while ($office_query->have_posts())
            {
                $office_query->the_post();

                if (get_post_meta(get_the_ID(), 'primary', TRUE) == '1')
                {
                	$primary_office_id = get_the_ID();
                }
            }
        }
        $office_query->reset_postdata();

        do_action( "propertyhive_pre_import_properties_sspc_json", $this->properties );
        $this->properties = apply_filters( "propertyhive_sspc_json_properties_due_import", $this->properties );

		$this->add_log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			$this->add_log( 'Importing property ' . $property_row .' with reference ' . (string)$property->id, (string)$property->id );

			$inserted_updated = false;

			$args = array(
	            'post_type' => 'property',
	            'posts_per_page' => 1,
	            'post_status' => 'any',
	            'meta_query' => array(
	            	array(
		            	'key' => $imported_ref_key,
		            	'value' => (string)$property->id
		            )
	            )
	        );
	        $property_query = new WP_Query($args);
	        
	        if ($property_query->have_posts())
	        {
	        	$this->add_log( 'This property has been imported before. Updating it', (string)$property->id );

	        	// We've imported this property before
	            while ($property_query->have_posts())
	            {
	                $property_query->the_post();

	                $post_id = get_the_ID();

	                $my_post = array(
				    	'ID'          	 => $post_id,
				    	'post_title'     => wp_strip_all_tags( (string)$property->address ),
				    	'post_excerpt'   => (string)$property->summary,
				    	'post_content' 	 => '',
				    	'post_status'    => 'publish',
				  	);

				 	// Update the post into the database
				    $post_id = wp_update_post( $my_post );

				    if ( is_wp_error( $post_id ) ) 
					{
						$this->add_error( 'ERROR: Failed to update post. The error was as follows: ' . $post_id->get_error_message(), (string)$property->id );
					}
					else
					{
						$inserted_updated = 'updated';
					}
	            }
	        }
	        else
	        {
	        	$this->add_log( 'This property hasn\'t been imported before. Inserting it', (string)$property->id );

	        	// We've not imported this property before
				$postdata = array(
					'post_excerpt'   => (string)$property->summary,
					'post_content' 	 => '',
					'post_title'     => wp_strip_all_tags( (string)$property->address ),
					'post_status'    => 'publish',
					'post_type'      => 'property',
					'comment_status' => 'closed',
				);

				$post_id = wp_insert_post( $postdata, true );

				if ( is_wp_error( $post_id ) ) 
				{
					$this->add_error( 'Failed to insert post. The error was as follows: ' . $post_id->get_error_message(), (string)$property->id );
				}
				else
				{
					$inserted_updated = 'inserted';
				}
			}
			$property_query->reset_postdata();

			if ( $inserted_updated !== false )
			{
				// Need to check title and excerpt and see if they've gone in as blank but weren't blank in the feed
				// If they are, then do the encoding
				$inserted_post = get_post( $post_id );
				if ( 
					$inserted_post && 
					$inserted_post->post_title == '' && $inserted_post->post_excerpt == '' && 
					((string)$property->address != '' || (string)$property->summary != '')
				)
				{
					$my_post = array(
				    	'ID'          	 => $post_id,
				    	'post_title'     => htmlentities(mb_convert_encoding(wp_strip_all_tags( (string)$property->address ), 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
				    	'post_excerpt'   => htmlentities(mb_convert_encoding((string)$property->summary, 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
				    	'post_content' 	 => '',
				    	'post_name' 	 => sanitize_title((string)$property->address),
				    	'post_status'    => 'publish',
				  	);

				 	// Update the post into the database
				    wp_update_post( $my_post );
				}

				// Inserted property ok. Continue

				if ( $inserted_updated == 'updated' )
				{
					// Get all meta data so we can compare before and after to see what's changed
					$metadata_before = get_metadata('post', $post_id, '', true);

					// Get all taxonomy/term data
					$taxonomy_terms_before = array();
					$taxonomy_names = get_post_taxonomies( $post_id );
					foreach ( $taxonomy_names as $taxonomy_name )
					{
						$taxonomy_terms_before[$taxonomy_name] = wp_get_post_terms( $post_id, $taxonomy_name, array('fields' => 'ids') );
					}
				}

				$this->add_log( 'Successfully ' . $inserted_updated . ' post. The post ID is ' . $post_id, (string)$property->id );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->id );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->id );
				update_post_meta( $post_id, '_address_name_number', '' );
				update_post_meta( $post_id, '_address_street', ( ( isset($property->address) ) ? (string)$property->address : '' ) );
				update_post_meta( $post_id, '_address_two', '' );
				update_post_meta( $post_id, '_address_three', '' );
				update_post_meta( $post_id, '_address_four', '' );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

				$country = 'GB';
				update_post_meta( $post_id, '_address_country', $country );

				// Coordinates
				if ( isset($property->latitude) && isset($property->longitude) && (string)$property->latitude != '' && (string)$property->longitude != '' )
				{
					update_post_meta( $post_id, '_latitude', ( ( isset($property->latitude) ) ? (string)$property->latitude : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property->longitude) ) ? (string)$property->longitude : '' ) );
				}
				else
				{
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( $lat == '' || $lng == '' )
					{
						$api_key = get_option('propertyhive_google_maps_api_key', '');
						if ( $api_key != '' )
						{
							if ( ini_get('allow_url_fopen') )
							{
								// No lat lng. Let's get it
								$address_to_geocode = array();
								if ( trim($property->address) != '' ) { $address_to_geocode[] = (string)$property->address; }
								if ( trim($property->postcode) != '' ) { $address_to_geocode[] = (string)$property->postcode; }

								$request_url = "https://maps.googleapis.com/maps/api/geocode/xml?address=" . urlencode( implode( ", ", $address_to_geocode ) ) . "&sensor=false&region=gb"; // the request URL you'll send to google to get back your XML feed
			                    
								if ( $api_key != '' ) { $request_url .= "&key=" . $api_key; }

					            $xml = simplexml_load_file($request_url);

					            if ( $xml !== FALSE )
				            	{
						            $status = $xml->status; // Get the request status as google's api can return several responses
						            
						            if ($status == "OK") 
						            {
						                //request returned completed time to get lat / lang for storage
						                $lat = (string)$xml->result->geometry->location->lat;
						                $lng = (string)$xml->result->geometry->location->lng;
						                
						                if ($lat != '' && $lng != '')
						                {
						                    update_post_meta( $post_id, '_latitude', $lat );
						                    update_post_meta( $post_id, '_longitude', $lng );
						                }
						            }
						            else
							        {
							        	$this->add_error( 'Google Geocoding service returned status ' . $status, (string)$property->id );
							        	sleep(3);
							        }
							    }
							    else
						        {
						        	$this->add_error( 'Failed to parse XML response from Google Geocoding service.', (string)$property->id );
						        }
							}
							else
					        {
					        	$this->add_error( 'Failed to obtain co-ordinates as allow_url_fopen setting is disabled', (string)$property->id );
					        }
					    }
					    else
					    {
					    	$this->add_log( 'Not performing Google Geocoding request as no API key present in settings', (string)$property->id );
					    }
					}
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
				$office_id = $primary_office_id;
				/*if ( isset($_POST['mapped_office'][(string)$property->branchID]) && $_POST['mapped_office'][(string)$property->branchID] != '' )
				{
					$office_id = $_POST['mapped_office'][(string)$property->branchID];
				}
				elseif ( isset($options['offices']) && is_array($options['offices']) && !empty($options['offices']) )
				{
					foreach ( $options['offices'] as $ph_office_id => $branch_code )
					{
						if ( $branch_code == (string)$property->branchID )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}*/
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = 'residential-sales';
				if ( (string)$property->property_category == '0' && (string)$property->transaction_type == '2' )
				{
					$department = 'residential-lettings';
				}
				elseif ( (string)$property->property_category == '1'  )
				{
					$department = 'commercial';
				}

				update_post_meta( $post_id, '_department', $department );

				if ( $department == 'residential-sales' || $department == 'residential-lettings' )
				{
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bedrooms) ) ? (string)$property->bedrooms : '' ) );
					update_post_meta( $post_id, '_bathrooms', '' );
					update_post_meta( $post_id, '_reception_rooms', '' );

					$prefix = '';
					if ( isset($_POST['mapped_' . $prefix . 'property_type']) )
					{
						$mapping = $_POST['mapped_' . $prefix . 'property_type'];
					}
					else
					{
						$mapping = isset($options['mappings'][$prefix . 'property_type']) ? $options['mappings'][$prefix . 'property_type'] : array();
					}
var_dump($mapping);
					wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

					if ( isset($property->property_type) )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->property_type]) )
						{
							wp_set_post_terms( $post_id, $mapping[(string)$property->property_type], $prefix . 'property_type' );
						}
						else
						{
							$this->add_log( 'Property received with a type (' . (string)$property->property_type . ') that is not mapped', (string)$property->id );
						}
					}
				}

				// Residential Sales Details
				if ( $department == 'residential-sales' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', ( strtolower($property->pricetype) == 'poa' ||  strtolower($property->pricetype) == 'price on application' ) ? 'yes' : '' );

					// Price Qualifier
					if ( isset($_POST['mapped_price_qualifier']) )
					{
						$mapping = $_POST['mapped_price_qualifier'];
					}
					else
					{
						$mapping = isset($options['mappings']['price_qualifier']) ? $options['mappings']['price_qualifier'] : array();
					}

					wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
					if ( !empty($mapping) && isset($property->pricetype) && isset($mapping[(string)$property->pricetype]) )
					{
		                wp_set_post_terms( $post_id, $mapping[(string)$property->pricetype], 'price_qualifier' );
		            }

		            // Tenure
		            /*if ( isset($_POST['mapped_tenure']) )
					{
						$mapping = $_POST['mapped_tenure'];
					}
					else
					{
						$mapping = isset($options['mappings']['tenure']) ? $options['mappings']['tenure'] : array();
					}

		            wp_delete_object_term_relationships( $post_id, 'tenure' );
					if ( !empty($mapping) && isset($property->propertyTenure) && isset($mapping[(string)$property->propertyTenure]) )
					{
			            wp_set_post_terms( $post_id, $mapping[(string)$property->propertyTenure], 'tenure' );
		            }

		            // Sale By
		            if ( isset($_POST['mapped_sale_by']) )
					{
						$mapping = $_POST['sale_by'];
					}
					else
					{
						$mapping = isset($options['mappings']['sale_by']) ? $options['mappings']['sale_by'] : array();
					}

		            wp_delete_object_term_relationships( $post_id, 'sale_by' );
					if ( !empty($mapping) && isset($property->saleBy) && isset($mapping[(string)$property->saleBy]) )
					{
			            wp_set_post_terms( $post_id, $mapping[(string)$property->saleBy], 'sale_by' );
		            }*/
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					$price_actual = $price;
					/*switch ( strtolower($property->pricetype) )
					{
						case "per calendar month": { $rent_frequency = 'pcm'; break; }
					}*/
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					
					update_post_meta( $post_id, '_poa', ( strtolower($property->pricetype) == 'poa' ||  strtolower($property->pricetype) == 'price on application' ) ? 'yes' : '' );

					update_post_meta( $post_id, '_deposit', '' );
            		update_post_meta( $post_id, '_available_date', '' );
				}
				elseif ( $department == 'commercial' )
				{
					update_post_meta( $post_id, '_for_sale', '' );
            		update_post_meta( $post_id, '_to_rent', '' );

            		if ( (string)$property->transaction_type == '1' )
	                {
	                    update_post_meta( $post_id, '_for_sale', 'yes' );

	                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

	                    $price = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    if ( $price == '' || $price == '0' )
	                    {
	                        $price = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    }
	                    update_post_meta( $post_id, '_price_from', $price );

	                    $price = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    if ( $price == '' || $price == '0' )
	                    {
	                        $price = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    }
	                    update_post_meta( $post_id, '_price_to', $price );

	                    update_post_meta( $post_id, '_price_units', '' );

	                    update_post_meta( $post_id, '_price_poa', ( strtolower($property->pricetype) == 'poa' ||  strtolower($property->pricetype) == 'price on application' ) ? 'yes' : '' );
	                }

	                if ( (string)$property->transaction_type == '2' )
	                {
	                    update_post_meta( $post_id, '_to_rent', 'yes' );

	                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

	                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    if ( $rent == '' || $rent == '0' )
	                    {
	                        $rent = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    }
	                    update_post_meta( $post_id, '_rent_from', $rent );

	                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    if ( $rent == '' || $rent == '0' )
	                    {
	                        $rent = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    }
	                    update_post_meta( $post_id, '_rent_to', $rent );

	                    $rent_units = 'pcm';
	                    switch ( strtolower($property->pricetype) )
	                    {
	                    	case "per calendar month": { $rent_units = 'pcm'; break; }
	                    }
	                    update_post_meta( $post_id, '_rent_units', $rent_units);

	                    update_post_meta( $post_id, '_rent_poa', ( strtolower($property->pricetype) == 'poa' ||  strtolower($property->pricetype) == 'price on application' ) ? 'yes' : '' );
	                }

	                // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

		            $size = '';
		            update_post_meta( $post_id, '_floor_area_from', $size );

		            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, 'sqft' ) );

		            $size = '';
		            update_post_meta( $post_id, '_floor_area_to', $size );

		            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, 'sqft' ) );

		            update_post_meta( $post_id, '_floor_area_units', 'sqft' );

		            $size = '';
		            update_post_meta( $post_id, '_site_area_from', $size );

		            update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, 'sqft' ) );

		            update_post_meta( $post_id, '_site_area_to', $size );

		            update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( $size, 'sqft' ) );

		            update_post_meta( $post_id, '_site_area_units', 'sqft' );
				}

				// Marketing
				update_post_meta( $post_id, '_on_market', 'yes' );
				update_post_meta( $post_id, '_featured', '' );

				// Availability
				$prefix = '';
				if ( isset($_POST['mapped_' . $prefix . 'availability']) )
				{
					$mapping = $_POST['mapped_' . $prefix . 'availability'];
				}
				else
				{
					$mapping = isset($options['mappings'][$prefix . 'availability']) ? $options['mappings'][$prefix . 'availability'] : array();
				}

        		wp_delete_object_term_relationships( $post_id, 'availability' );
				if ( !empty($mapping) && isset($property->status_name) && isset($mapping[(string)$property->status_name]) )
				{
	                wp_set_post_terms( $post_id, $mapping[(string)$property->status_name], 'availability' );
	            }

	            // Features
				/*$features = array();
				for ( $i = 1; $i <= 10; ++$i )
				{
					if ( isset($property->{'feature_' . $i}) && trim((string)$property->{'feature_' . $i}) != '' )
					{
						$features[] = trim((string)$property->{'feature_' . $i});
					}
				}

				update_post_meta( $post_id, '_features', count( $features ) );
        		
        		$i = 0;
		        foreach ( $features as $feature )
		        {
		            update_post_meta( $post_id, '_feature_' . $i, $feature );
		            ++$i;
		        }*/ 

		        // Rooms / Descriptions
				update_post_meta( $post_id, '_rooms', '1' );
				update_post_meta( $post_id, '_room_name_0', '' );
	            update_post_meta( $post_id, '_room_dimensions_0', '' );
	            update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", (string)$property->description) );

	            // Media - Images
	            $media_urls = array();
	            if ( isset($property->gallery) && is_array($property->gallery) && !empty($property->gallery) )
	            {
		            foreach ( $property->gallery as $gallery )
					{
						if ( 
							isset($gallery->gallery_photo)
						)
						{
							$url = explode("?", $gallery->gallery_photo);
							$media_urls[] = $url[0];
						}
					}
				}
				else
				{
					if ( isset($property->photo) && $property->photo != '' )
					{
						$media_urls[] = (string)$property->photo;
					}
				}

	            if ( get_option('propertyhive_images_stored_as', '') == 'urls' )
    			{
					update_post_meta( $post_id, '_photo_urls', $media_urls );

					$this->add_log( 'Imported ' . count($media_urls) . ' photo URLs', (string)$property->id );
    			}
    			else
    			{
					$media_ids = array();
					$new = 0;
					$existing = 0;
					$deleted = 0;
					$previous_media_ids = get_post_meta( $post_id, '_photos', TRUE );
					if ( !empty($media_urls) )
	                {
	                    foreach ($media_urls as $url)
	                    {
							if ( 
								substr( strtolower($url), 0, 2 ) == '//' || 
								substr( strtolower($url), 0, 4 ) == 'http'
							)
							{
								// This is a URL
								$description = '';

								$filename = basename( $url );

								// Check, based on the URL, whether we have previously imported this media
								$imported_previously = false;
								$imported_previously_id = '';
								if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
								{
									foreach ( $previous_media_ids as $previous_media_id )
									{
										if ( 
											get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url
										)
										{
											$imported_previously = true;
											$imported_previously_id = $previous_media_id;
											break;
										}
									}
								}
								
								if ($imported_previously)
								{
									$media_ids[] = $imported_previously_id;

									++$existing;
								}
								else
								{
								    $tmp = download_url( $url );
								    $file_array = array(
								        'name' => basename( $url ),
								        'tmp_name' => $tmp
								    );

								    // Check for download errors
								    if ( is_wp_error( $tmp ) ) 
								    {
								        @unlink( $file_array[ 'tmp_name' ] );

								        $this->add_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->id );
								    }
								    else
								    {
									    $id = media_handle_sideload( $file_array, $post_id, $description, array('post_title' => $filename) );

									    // Check for handle sideload errors.
									    if ( is_wp_error( $id ) ) 
									    {
									        @unlink( $file_array['tmp_name'] );
									        
									        $this->add_error( 'ERROR: An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->id );
									    }
									    else
									    {
									    	$media_ids[] = $id;

									    	update_post_meta( $id, '_imported_url', $url);

									    	++$new;
									    }
									}
								}
							}
						}
					}
					update_post_meta( $post_id, '_photos', $media_ids );

					// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
					if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
					{
						foreach ( $previous_media_ids as $previous_media_id )
						{
							if ( !in_array($previous_media_id, $media_ids) )
							{
								if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
								{
									++$deleted;
								}
							}
						}
					}

					$this->add_log( 'Imported ' . count($media_ids) . ' photos (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', (string)$property->id );
				}

				// Media - Floorplans
				/*$media_urls = array();
	            for ( $i = 1; $i <= 50; ++$i )
				{
					if ( 
						isset($property->assets->{'item' . $i}) && 
						isset($property->assets->{'item' . $i}->url) &&
						trim((string)$property->assets->{'item' . $i}->url) != '' &&
						isset($property->assets->{'item' . $i}->type_id) &&
						$property->assets->{'item' . $i}->type_id == '2'
					)
					{
						$media_urls[] = (string)$property->assets->{'item' . $i}->url;
					}
				}

				if ( get_option('propertyhive_floorplans_stored_as', '') == 'urls' )
    			{
					update_post_meta( $post_id, '_floorplan_urls', $media_urls );

					$this->add_log( 'Imported ' . count($media_urls) . ' floorplan URLs', (string)$property->id );
    			}
    			else
    			{
					$media_ids = array();
					$new = 0;
					$existing = 0;
					$deleted = 0;
					$previous_media_ids = get_post_meta( $post_id, '_floorplans', TRUE );
					if ( !empty($media_urls) )
	                {
	                    foreach ($media_urls as $url)
	                    {
							if ( 
								substr( strtolower($url), 0, 2 ) == '//' || 
								substr( strtolower($url), 0, 4 ) == 'http'
							)
							{
								$description = '';
							    
								$filename = basename( $url );

								// Check, based on the URL, whether we have previously imported this media
								$imported_previously = false;
								$imported_previously_id = '';
								if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
								{
									foreach ( $previous_media_ids as $previous_media_id )
									{
										if ( 
											get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url
										)
										{
											$imported_previously = true;
											$imported_previously_id = $previous_media_id;
											break;
										}
									}
								}
								
								if ($imported_previously)
								{
									$media_ids[] = $imported_previously_id;

									++$existing;
								}
								else
								{
								    $tmp = download_url( $url );
								    $file_array = array(
								        'name' => $filename,
								        'tmp_name' => $tmp
								    );

								    // Check for download errors
								    if ( is_wp_error( $tmp ) ) 
								    {
								        @unlink( $file_array[ 'tmp_name' ] );

								        $this->add_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->id );
								    }
								    else
								    {
									    $id = media_handle_sideload( $file_array, $post_id, $description, array('post_title' => $filename) );

									    // Check for handle sideload errors.
									    if ( is_wp_error( $id ) ) 
									    {
									        @unlink( $file_array['tmp_name'] );
									        
									        $this->add_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->id );
									    }
									    else
									    {
									    	$media_ids[] = $id;

									    	update_post_meta( $id, '_imported_url', $url);

									    	++$new;
									    }
									}
								}
							}
						}
					}
					update_post_meta( $post_id, '_floorplans', $media_ids );

					// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
					if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
					{
						foreach ( $previous_media_ids as $previous_media_id )
						{
							if ( !in_array($previous_media_id, $media_ids) )
							{
								if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
								{
									++$deleted;
								}
							}
						}
					}

					$this->add_log( 'Imported ' . count($media_ids) . ' floorplans (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', (string)$property->id );
				}

				// Media - Brochures
				$media_urls = array();
	            for ( $i = 1; $i <= 50; ++$i )
				{
					if ( 
						isset($property->assets->{'item' . $i}) && 
						isset($property->assets->{'item' . $i}->url) &&
						trim((string)$property->assets->{'item' . $i}->url) != '' &&
						isset($property->assets->{'item' . $i}->type_id) &&
						$property->assets->{'item' . $i}->type_id == '4'
					)
					{
						$media_urls[] = (string)$property->assets->{'item' . $i}->url;
					}
				}

				if ( get_option('propertyhive_brochures_stored_as', '') == 'urls' )
    			{
					update_post_meta( $post_id, '_brochure_urls', $media_urls );

					$this->add_log( 'Imported ' . count($media_urls) . ' brochure URLs', (string)$property->id );
    			}
    			else
    			{
					$media_ids = array();
					$new = 0;
					$existing = 0;
					$deleted = 0;
					$previous_media_ids = get_post_meta( $post_id, '_brochures', TRUE );
					if ( !empty($media_urls) )
	                {
	                    foreach ($media_urls as $url)
	                    {
							if ( 
								substr( strtolower($url), 0, 2 ) == '//' || 
								substr( strtolower($url), 0, 4 ) == 'http'
							)
							{
								// This is a URL
								$description = '';
							    
								$filename = basename( $url );

								// Check, based on the URL, whether we have previously imported this media
								$imported_previously = false;
								$imported_previously_id = '';
								if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
								{
									foreach ( $previous_media_ids as $previous_media_id )
									{
										if ( 
											get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url
										)
										{
											$imported_previously = true;
											$imported_previously_id = $previous_media_id;
											break;
										}
									}
								}
								
								if ($imported_previously)
								{
									$media_ids[] = $imported_previously_id;

									++$existing;
								}
								else
								{
								    $tmp = download_url( $url );
								    $file_array = array(
								        'name' => $filename,
								        'tmp_name' => $tmp
								    );

								    // Check for download errors
								    if ( is_wp_error( $tmp ) ) 
								    {
								        @unlink( $file_array[ 'tmp_name' ] );

								        $this->add_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->id );
								    }
								    else
								    {
									    $id = media_handle_sideload( $file_array, $post_id, $description, array('post_title' => $filename) );

									    // Check for handle sideload errors.
									    if ( is_wp_error( $id ) ) 
									    {
									        @unlink( $file_array['tmp_name'] );
									        
									        $this->add_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->id );
									    }
									    else
									    {
									    	$media_ids[] = $id;

									    	update_post_meta( $id, '_imported_url', $url);

									    	++$new;
									    }
									}
								}
							}
						}
					}
					update_post_meta( $post_id, '_brochures', $media_ids );

					// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
					if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
					{
						foreach ( $previous_media_ids as $previous_media_id )
						{
							if ( !in_array($previous_media_id, $media_ids) )
							{
								if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
								{
									++$deleted;
								}
							}
						}
					}

					$this->add_log( 'Imported ' . count($media_ids) . ' brochures (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', (string)$property->id );
				}

				// Media - EPCs
				$media_urls = array();
	            for ( $i = 1; $i <= 50; ++$i )
				{
					if ( 
						isset($property->assets->{'item' . $i}) && 
						isset($property->assets->{'item' . $i}->url) &&
						trim((string)$property->assets->{'item' . $i}->url) != '' &&
						isset($property->assets->{'item' . $i}->type_id) &&
						$property->assets->{'item' . $i}->type_id == '4'
					)
					{
						$media_urls[] = (string)$property->assets->{'item' . $i}->url;
					}
				}

				if ( get_option('propertyhive_epcs_stored_as', '') == 'urls' )
    			{
    				$media_urls = array();

					update_post_meta( $post_id, '_epc_urls', $media_urls );

					$this->add_log( 'Imported ' . count($media_urls) . ' EPC URLs', (string)$property->id );
    			}
    			else
    			{
					$media_ids = array();
					$new = 0;
					$existing = 0;
					$deleted = 0;
					$previous_media_ids = get_post_meta( $post_id, '_epcs', TRUE );
					if ( !empty($media_urls) )
	                {
	                    foreach ($media_urls as $url)
	                    {
							if ( 
								substr( strtolower($url), 0, 2 ) == '//' || 
								substr( strtolower($url), 0, 4 ) == 'http'
							)
							{
								// This is a URL
								$description = '';
							    
								$filename = basename( $url );

								// Check, based on the URL, whether we have previously imported this media
								$imported_previously = false;
								$imported_previously_id = '';
								if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
								{
									foreach ( $previous_media_ids as $previous_media_id )
									{
										if ( 
											get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url
										)
										{
											$imported_previously = true;
											$imported_previously_id = $previous_media_id;
											break;
										}
									}
								}
								
								if ($imported_previously)
								{
									$media_ids[] = $imported_previously_id;

									++$existing;
								}
								else
								{
								    $tmp = download_url( $url );
								    $file_array = array(
								        'name' => $filename,
								        'tmp_name' => $tmp
								    );

								    // Check for download errors
								    if ( is_wp_error( $tmp ) ) 
								    {
								        @unlink( $file_array[ 'tmp_name' ] );

								        $this->add_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->id );
								    }
								    else
								    {
									    $id = media_handle_sideload( $file_array, $post_id, $description, array('post_title' => $filename) );

									    // Check for handle sideload errors.
									    if ( is_wp_error( $id ) ) 
									    {
									        @unlink( $file_array['tmp_name'] );
									        
									        $this->add_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->id );
									    }
									    else
									    {
									    	$media_ids[] = $id;

									    	update_post_meta( $id, '_imported_url', $url);

									    	++$new;
									    }
									}
								}
							}
						}
					}
					update_post_meta( $post_id, '_epcs', $media_ids );

					// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
					if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
					{
						foreach ( $previous_media_ids as $previous_media_id )
						{
							if ( !in_array($previous_media_id, $media_ids) )
							{
								if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
								{
									++$deleted;
								}
							}
						}
					}

					$this->add_log( 'Imported ' . count($media_ids) . ' EPCs (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', (string)$property->id );
				}*/

				// Media - Virtual Tours
				/*$virtual_tours = array();
				if (isset($property->virtualTours) && !empty($property->virtualTours))
                {
                    foreach ($property->virtualTours as $virtualTours)
                    {
                        if (!empty($virtualTours->virtualTour))
                        {
                            foreach ($virtualTours->virtualTour as $virtualTour)
                            {
                            	$virtual_tours[] = $virtualTour;
                            }
                        }
                    }
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->add_log( 'Imported ' . count($virtual_tours) . ' virtual tours', (string)$property->id );*/

				do_action( "propertyhive_property_imported_sspc_json", $post_id, $property );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					// Compare meta/taxonomy data before and after.

					$metadata_after = get_metadata('post', $post_id, '', true);

					foreach ( $metadata_after as $key => $value)
					{
						if ( in_array($key, array('_photos', '_photo_urls', '_floorplans', '_floorplan_urls', '_brochures', '_brochure_urls', '_epcs', '_epc_urls', '_virtual_tours')) )
						{
							continue;
						}

						if ( !isset($metadata_before[$key]) )
						{
							$this->add_log( 'New meta data for ' . trim($key, '_') . ': ' . ( ( is_array($value) ) ? implode(", ", $value) : $value ), (string)$property->id );
						}
						elseif ( $metadata_before[$key] != $metadata_after[$key] )
						{
							$this->add_log( 'Updated ' . trim($key, '_') . '. Before: ' . ( ( is_array($metadata_before[$key]) ) ? implode(", ", $metadata_before[$key]) : $metadata_before[$key] ) . ', After: ' . ( ( is_array($value) ) ? implode(", ", $value) : $value ), (string)$property->id );
						}
					}

					$taxonomy_terms_after = array();
					$taxonomy_names = get_post_taxonomies( $post_id );
					foreach ( $taxonomy_names as $taxonomy_name )
					{
						$taxonomy_terms_after[$taxonomy_name] = wp_get_post_terms( $post_id, $taxonomy_name, array('fields' => 'ids') );
					}

					foreach ( $taxonomy_terms_after as $taxonomy_name => $ids)
					{
						if ( !isset($taxonomy_terms_before[$taxonomy_name]) )
						{
							$this->add_log( 'New taxonomy data for ' . $taxonomy_name . ': ' . ( ( is_array($ids) ) ? implode(", ", $ids) : $ids ), (string)$property->id );
						}
						elseif ( $taxonomy_terms_before[$taxonomy_name] != $taxonomy_terms_after[$taxonomy_name] )
						{
							$this->add_log( 'Updated ' . $taxonomy_name . '. Before: ' . ( ( is_array($taxonomy_terms_before[$taxonomy_name]) ) ? implode(", ", $taxonomy_terms_before[$taxonomy_name]) : $taxonomy_terms_before[$taxonomy_name] ) . ', After: ' . ( ( is_array($ids) ) ? implode(", ", $ids) : $ids ), (string)$property->id );
						}
					}
				}
			}

			if ( 
				isset($options['chunk_qty']) && $options['chunk_qty'] != '' && 
				isset($options['chunk_delay']) && $options['chunk_delay'] != '' &&
				($property_row % $options['chunk_qty'] == 0)
			)
			{
				$this->add_log( 'Pausing for ' . $options['chunk_delay'] . ' seconds' );
				sleep($options['chunk_delay']);
			}
			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_sspc_json" );

		$this->import_end();

		$this->add_log( 'Finished import' );
	}

	private function import_start()
	{
		wp_suspend_cache_invalidation( true );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
	}

	private function import_end()
	{
		wp_cache_flush();

		wp_suspend_cache_invalidation( false );

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
	}

	public function remove_old_properties( $import_id = '', $do_remove = true )
	{
		global $wpdb, $post;

		$options = get_option( 'propertyhive_property_import' );
		if (isset($options[$import_id]))
		{
			$options = $options[$import_id];
		}
		else
		{
			$options = array();
		}

		$imported_ref_key = ( ( $import_id != '' ) ? '_imported_ref_' . $import_id : '_imported_ref' );

		// Get all properties that:
		// a) Don't have an _imported_ref matching the properties in $this->properties;
		// b) Haven't been manually added (.i.e. that don't have an _imported_ref at all)

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->id;
		}

		$args = array(
			'post_type' => 'property',
			'nopaging' => true
		);

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => $imported_ref_key,
				'value'   => $import_refs,
				'compare' => 'NOT IN',
			),
			array(
				'key'     => '_on_market',
				'value'   => 'yes',
			),
		);

		$args['meta_query'] = $meta_query;

		$property_query = new WP_Query( $args );
		if ( $property_query->have_posts() )
		{
			while ( $property_query->have_posts() )
			{
				$property_query->the_post();

				if ($do_remove)
				{
					update_post_meta( $post->ID, '_on_market', '' );

					$this->add_log( 'Property ' . $post->ID . ' marked as not on market', get_post_meta($post->ID, $imported_ref_key, TRUE) );

					if ( isset($options['remove_action']) && $options['remove_action'] != '' )
					{
						if ( $options['remove_action'] == 'remove_all_media' || $options['remove_action'] == 'remove_all_media_except_first_image' )
						{
							// Remove all EPCs
							$this->delete_media( $post->ID, '_epcs' );

							// Remove all Brochures
							$this->delete_media( $post->ID, '_brochures' );

							// Remove all Floorplans
							$this->delete_media( $post->ID, '_floorplans' );

							// Remove all Images (except maybe the first)
							$this->delete_media( $post->ID, '_photos', ( ( $options['remove_action'] == 'remove_all_media_except_first_image' ) ? TRUE : FALSE ) );

							$this->add_log( 'Deleted property media', get_post_meta($post->ID, $imported_ref_key, TRUE) );
						}
					}
				}

				do_action( "propertyhive_property_removed_sspc_json", $post->ID );
			}
		}
		wp_reset_postdata();

		unset($import_refs);
	}

	private function delete_media( $post_id, $meta_key, $except_first = false )
	{
		$media_ids = get_post_meta( $post_id, $meta_key, TRUE );
		if ( !empty( $media_ids ) )
		{
			$i = 0;
			foreach ( $media_ids as $media_id )
			{
				if ( !$except_first || ( $except_first && $i > 0 ) )
				{
					if ( wp_delete_attachment( $media_id, TRUE ) !== FALSE )
					{
						// Deleted succesfully. Now remove from array
						if( ($key = array_search($media_id, $media_ids)) !== false)
						{
						    unset($media_ids[$key]);
						}
					}
					else
					{
						$this->add_error( 'Failed to delete ' . $meta_key . ' with attachment ID ' . $media_id, get_post_meta($post_id, $imported_ref_key, TRUE) );
					}
				}
				++$i;
			}
		}
		update_post_meta( $post_id, $meta_key, $media_ids );
	}

	public function get_properties()
	{
		return $this->properties;
	}

	private function add_error( $message, $agent_ref = '' )
	{
		if ( $this->instance_id != '' )
		{
			global $wpdb;
        
	        $wpdb->insert( 
	            $wpdb->prefix . "ph_propertyimport_logs_instance_log", 
	            array(
	                'instance_id' => $this->instance_id,
	                'severity' => 1,
	                'entry' => substr( ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message, 0, 255),
	                'log_date' => date("Y-m-d H:i:s")
	            )
	        );

	        if ( defined( 'WP_CLI' ) && WP_CLI )
        	{
        		WP_CLI::log( date("Y-m-d H:i:s") . ' - ' . ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message );
        	}
		}

		$this->errors[] = date("Y-m-d H:i:s") . ' - ' . ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message;
	}

	public function get_errors()
	{
		return $this->errors;
	}

	public function clear_errors()
	{
		$this->errors = array();
	}

	public function get_mappings()
	{
		if ( !empty($this->mappings) )
		{
			return $this->mappings;
		}

		// Build mappings
		$mapping_values = $this->get_json_mapping_values('property_type');
		if ( is_array($mapping_values) && !empty($mapping_values) )
		{
			foreach ($mapping_values as $mapping_value => $text_value)
			{
				$this->mappings['property_type'][$mapping_value] = '';
			}
		}

		$mapping_values = $this->get_json_mapping_values('price_qualifier');
		if ( is_array($mapping_values) && !empty($mapping_values) )
		{
			foreach ($mapping_values as $mapping_value => $text_value)
			{
				$this->mappings['price_qualifier'][$mapping_value] = '';
			}
		}

		$mapping_values = $this->get_json_mapping_values('office');
		if ( is_array($mapping_values) && !empty($mapping_values) )
		{
			foreach ($mapping_values as $mapping_value => $text_value)
			{
				$this->mappings['office'][$mapping_value] = '';
			}
		}
		
		return $this->mappings;
	}

	private function add_log( $message, $agent_ref = '' )
	{
		if ( $this->instance_id != '' )
		{
			global $wpdb;
        
	        $wpdb->insert( 
	            $wpdb->prefix . "ph_propertyimport_logs_instance_log", 
	            array(
	                'instance_id' => $this->instance_id,
	                'severity' => 0,
	                'entry' => substr( ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message, 0, 255),
	                'log_date' => date("Y-m-d H:i:s")
	            )
	        );

	        if ( defined( 'WP_CLI' ) && WP_CLI )
        	{
        		WP_CLI::log( date("Y-m-d H:i:s") . ' - ' . ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message );
        	}
		}

		$this->import_log[] = date("Y-m-d H:i:s") . ' - ' . ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message;
	}

	public function get_import_log()
	{
		return $this->import_log;
	}

	public function get_mapping_values($custom_field)
	{
		return $this->get_json_mapping_values($custom_field);
	}

	public function get_json_mapping_values($custom_field) 
	{
        if ($custom_field == 'property_type')
        {
        	return array(
                'Flat' => 'Flat',
                'House' => 'House',
                'Bungalow' => 'Bungalow',
                'Plot' => 'Plot',
            );
        }
        if ($custom_field == 'price_qualifier')
        {
        	return array(
        		'Fixed Price' => 'Fixed Price',
        		'Guide Price' => 'Guide Price',
        		'Offers Over' => 'Offers Over',
        		'Around' => 'Around',
        	);
        }
    }

}

}