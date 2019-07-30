<?php
/**
 * Plugin Name: Property Hive Property Import SSPC Add On
 * Plugin Uri: http://wp-property-hive.com/addons/property-import/
 * Description: Add On for Property Hive extending the Property Import add on allowing you to import properties from SSPC
 * Version: 1.0.2
 * Author: PropertyHive
 * Author URI: http://wp-property-hive.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_Property_Import_SSPC' ) ) :

final class PH_Property_Import_SSPC {

    /**
     * @var string
     */
    public $version = '1.0.2';

    /**
     * @var Property Hive The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main Property Hive Property Import SSPC Instance
     *
     * Ensures only one instance of Property Hive Property Import SSPC is loaded or can be loaded.
     *
     * @static
     * @return Property Hive Property Import SSPC - Main instance
     */
    public static function instance() 
    {
        if ( is_null( self::$_instance ) ) 
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {

        $this->id    = 'propertyimportsspc';
        $this->label = __( 'Import Properties', 'propertyhive' );

        // Define constants
        $this->define_constants();

        // Include required files
        $this->includes();

        add_filter( 'propertyhive_property_import_format_name', array( $this, 'set_property_import_format_name' ), 10, 2 );
        add_filter( 'propertyhive_property_import_format_details', array( $this, 'set_property_import_format_details' ), 10, 2 );

        add_action( 'propertyhive_property_import_setup_details', array( $this, 'propertyhive_property_import_setup_details' ), 10, 1 );

        add_filter( 'propertyhive_property_import_setup_details_save', array( $this, 'propertyhive_property_import_setup_details_save' ), 10, 1 );

        add_action( 'propertyhive_property_import_cron', array( $this, 'propertyhive_property_import_cron' ), 10, 3 );

        add_filter( 'propertyhive_property_import_object', array( $this, 'propertyhive_property_import_object' ), 10, 2 );
    }

    /**
     * Define PH Property Import SSPC Constants
     */
    private function define_constants() 
    {
        define( 'PH_PROPERTYIMPORT_SSPC_PLUGIN_FILE', __FILE__ );
        define( 'PH_PROPERTYIMPORT_SSPC_VERSION', $this->version );
    }

    private function includes()
    {
        //include_once( 'includes/class-ph-property-import-install.php' );
    }

    public function set_property_import_format_name( $name, $options )
    {
        if ( isset($options['format']) && $options['format'] == 'json_sspc' )
        {
            $name = 'SSPC';
        }
        return $name;
    }

    public function set_property_import_format_details( $details, $options )
    {
        if ( isset($options['format']) && $options['format'] == 'json_sspc' )
        {
            $details = 'URL: ' . ( isset($options['url']) ? $options['url'] : '-' );
        }
        return $details;
    }

    public function propertyhive_property_import_setup_details( $options )
    {
        $this_format =
            ( isset($_POST['format']) && $_POST['format'] == 'json_sspc' ) ? 
                true : 
                ( ( isset($options['format']) && $options['format'] == 'json_sspc' ) ? true : false );
    ?>

    <table class="form-table">
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="format_json_sspc"><?php _e( 'SSPC JSON', 'propertyhive' ); ?></label>
            </th>
            <td class="forminp forminp-text">

                <label>
                    <input type="radio" name="format" id="format_json_sspc" value="json_sspc"<?php echo ( $this_format ? ' checked' : '' ); ?> />
                    Select
                </label>
                <br><br>
                <div class="format-options" id="json_sspc_options" style="display:<?php echo ( $this_format ? 'block' : 'none' ); ?>">
                    <table>
                        <tr>
                            <td>
                                URL
                            </td>
                            <td>
                                <input type="text" name="sspc_json_url" id="sspc_json_url" placeholder="http://" value="<?php
                                    echo ( isset($_POST['sspc_json_url']) ) ? 
                                        $_POST['sspc_json_url'] : 
                                        ( isset($options['url']) ? $options['url'] : '' );
                                ?>" />
                            </td>
                        </tr>
                    </table>
                </div>

            </td>
        </tr>
    </table>

    <?php
    }

    public function propertyhive_property_import_setup_details_save( $options )
    {
        if ( isset($_POST['format']) && $_POST['format'] == 'json_sspc' )
        {
            // includes
            require_once dirname( __FILE__ ) . '/includes/class-ph-sspc-json-import.php';

            $PH_SSPC_JSON_Import = new PH_SSPC_JSON_Import();

            $options = array_merge(
                $options, 
                array(
                    'url' => $_POST['sspc_json_url'],
                )
            );
        }
        return $options;
    }

    public function propertyhive_property_import_cron( $options, $instance_id, $import_id )
    {
        if ( isset($options['format']) && $options['format'] == 'json_sspc' )
        {
            $wp_upload_dir = wp_upload_dir();

            $json_file = $wp_upload_dir['basedir'] . '/ph_import/sspc_properties.json';

            $contents = '';

            $response = wp_remote_get( $options['url'], array( 'timeout' => 120 ) );

            if ( !is_wp_error($response) && is_array( $response ) ) 
            {
                $contents = $response['body'];
                $contents = iconv('UTF-8', 'UTF-8//IGNORE', utf8_encode($contents));
            }
            else
            {
                die("Failed to obtain JSON. Dump of response as follows: " . print_r($response, TRUE));
            }

            $handle = @fopen($json_file, 'w+');
            if ($handle)
            {
                fwrite($handle, $contents);
                fclose($handle);

                // We've got the file

                // Load Importer API
                require_once ABSPATH . 'wp-admin/includes/import.php';

                if ( ! class_exists( 'WP_Importer' ) ) {
                    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
                    if ( file_exists( $class_wp_importer ) ) require_once $class_wp_importer;
                }

                // includes
                require_once dirname( __FILE__ ) . '/includes/class-ph-sspc-json-import.php';

                $PH_SSPC_JSON_Import = new PH_SSPC_JSON_Import( $json_file, $instance_id );

                $parsed = $PH_SSPC_JSON_Import->parse();

                if ( $parsed )
                {
                    $PH_SSPC_JSON_Import->import( $import_id );

                    $PH_SSPC_JSON_Import->remove_old_properties( $import_id, (!isset($options['dont_remove']) || $options['dont_remove'] != '1') ? true : false );
                }

                unlink($json_file);
            }                           
            else
            {
                echo "Failed to write XML file locally. Please check file permissions";
            }
        }
    }

    public function propertyhive_property_import_object( $object, $format )
    {
        if ( $format == 'json_sspc' )
        {
            require_once dirname( __FILE__ ) . '/includes/class-ph-sspc-json-import.php';

            $object = new PH_SSPC_JSON_Import();
        }

        return $object;
    }
}

endif;

/**
 * Returns the main instance of PH_Property_Import_SSPC to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return PH_Property_Import
 */
function PHPISSPC() {
    return PH_Property_Import_SSPC::instance();
}

PHPISSPC();

/*if( is_admin() && file_exists(  dirname( __FILE__ ) . '/propertyhive-property-import-update.php' ) )
{
    include_once( dirname( __FILE__ ) . '/propertyhive-property-import-update.php' );
}*/