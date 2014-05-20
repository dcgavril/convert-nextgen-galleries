<?php
/*
Plugin Name: Convert NextGEN Galleries to WordPress
Plugin URI: 
Description: Converts NextGEN galleries to WordPress default galleries.
Version: 1.0
Author: Stefan Senk
Author URI: http://www.senktec.com
License: GPL2


Copyright 2014  Stefan Senk  (email : info@senktec.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

function cng_admin_url() {
	return admin_url('options-general.php?page=convert-nextgen-galleries.php');
}

function cng_get_posts_to_convert_query($shortcode = 'nggallery', $post_id = null, $max_number_of_posts = -1) {
	$args = array(
		's'           => '[' . $shortcode,
		'post_type'   => array( 'post', 'page' ),
		'post_status' => 'any',
		'p' => $post_id,
		'posts_per_page' => $max_number_of_posts
	);
	return new WP_Query( $args );
}

function cng_get_galleries_to_convert($gallery_id = null, $max_number_of_galleries = -1) {
	global $wpdb;
  if ($gallery_id)
  	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ngg_gallery WHERE gid = %d", $gallery_id ) );
  else
  	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ngg_gallery" ) );
}

function cng_find_shortcodes($post, $shortcode='nggallery') {
	$matches = null;
	preg_match_all( '/\[' . $shortcode . '.*?\]/si', $post->post_content, $matches );
	return $matches[0];
}

function cng_get_gallery_id_from_shortcode($shortcode) {
	$atts = shortcode_parse_atts($shortcode);
	return intval( $atts['id'] );
}

function cng_list_galleries($galleries) {
	echo '<h3>Listing ' . count($galleries) . ' galleries to convert:</h3>';

	echo '<table>';
	echo '<tr>';
	echo '<th>Gallery ID</th>';
	echo '<th>Gallery Title</th>';
	echo '<th colspan="2">Actions</th>';
	echo '<tr>';
	foreach ( $galleries as $gallery ) {
		echo '<tr>';
		echo '<td>' . $gallery->gid . '</td>';
		echo '<td>' . $gallery->title . '</td>';
		echo '<td><a href="' . admin_url('admin.php?page=nggallery-manage-gallery&mode=edit&gid=' . $gallery->gid) . '">Edit gallery</a></td>';
		echo '<td><a href="' . cng_admin_url() . '&amp;action=convert&gallery=' . $gallery->gid . '" class="button">Convert</a></td>';
		echo '<tr>';
	}
	echo '</table>';
}

function cng_convert_galleries($galleries) {
	global $wpdb;

	echo '<h3>Converting ' . count($galleries) . ' galleries:</h3>';

  $nggallery_query = cng_get_posts_to_convert_query('nggallery');
  $nggallery_posts = $nggallery_query->posts;

	foreach ( $galleries as $gallery ) {

	  $images = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ngg_pictures WHERE galleryid = %d ORDER BY sortorder, pid ASC", $gallery->gid ) );

	  $attachment_ids = array();

	  foreach ( $images as $image ) {
		  $existing_image_path =  ABSPATH . trailingslashit( $gallery->path ) . $image->filename;
		  if ( ! file_exists ($existing_image_path) ) {
			  echo "ERROR: File '$existing_image_path' not found.<br>";
			  continue;
		  }

		  $tmp_image_path = get_temp_dir() . $image->filename;
		  copy($existing_image_path, $tmp_image_path);

		  $file_array['name'] = $image->filename;
		  $file_array['tmp_name'] = $tmp_image_path;

		  if ( ! trim( $image->alttext ) )
			  $image->alttext = $image->filename;

		  $post_data = array(
			  'post_title' => $image->alttext,
			  'post_content' => $image->description,
			  /*'post_excerpt' => $image->description,*/
			  'menu_order' => $image->sortorder
		  );
		  $id = media_handle_sideload( $file_array, $post->ID, null, $post_data );
		  if ( is_wp_error($id) ) {
			  echo "ERROR: media_handle_sideload() filed for '$existing_image_path'.<br>";
			  continue;
		  }

		  array_push( $attachment_ids, $id );
		  $attachment = get_post( $id );
		  update_post_meta( $attachment->ID, '_wp_attachment_image_alt', $image->alttext );

	  }

	  if ( count( $attachment_ids ) == count( $images ) ) {
      foreach ( $nggallery_posts as $post ) {
	      foreach ( cng_find_shortcodes($post) as $shortcode ) {

		      $gid = cng_get_gallery_id_from_shortcode($shortcode);
          //echo "gid: " . $gid . "; " . "gallery->gid: " . $gallery->gid . "<br />"; 
          if ($gid == $gallery->gid) {
      	    echo '<h4>' . $post->post_title . '</h4>';

    				$new_shortcode = '[gallery columns="3" link="file" ids="'. implode( ',', $attachment_ids ) . '"]';
    				$post->post_content = str_replace( $shortcode, $new_shortcode, $post->post_content );
    				wp_update_post( $post );
    				echo "Replaced <code>$shortcode</code> with <code>$new_shortcode</code>.<br>";

          }
        }
      }
		} else {
			echo "<p>Not replacing <code>$shortcode</code>. " . count( $attachment_ids ) . " of " . count( $images ) . " images converted.</p>";
		}


  }
}

add_filter( 'plugin_action_links', function($links, $file) {
	if ( $file == 'convert-nextgen-galleries/convert-nextgen-galleries.php' ) {
		array_unshift( $links, '<a href="' . cng_admin_url() . '">' . __( 'Settings', 'convert-nextgen-galleries' ) . '</a>' );
	}
	return $links;
}, 10, 2 );

add_action('admin_menu', function() {
	add_options_page( 
		__( 'Convert NextGEN Galleries', 'convert-nextgen-galleries' ),
		__( 'Convert NextGEN Galleries', 'convert-nextgen-galleries' ),
		'manage_options', 'convert-nextgen-galleries.php', function() {

		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have permission to access this page.', 'convert-nextgen-galleries' ) );
		}
?>
		<div class="wrap">
			<h2><?php _e( 'Convert NextGEN Galleries to WordPress', 'convert-nextgen-galleries' ); ?></h2>
			<?php 
				$gallery_id = isset($_GET['gallery']) ? $_GET['gallery'] : null;
				$max_num_to_convert = isset($_GET['max_num']) ? $_GET['max_num'] : -1;

				$galleries_to_convert = cng_get_galleries_to_convert( $gallery_id, $max_num_to_convert );

				if ( isset( $_GET['action'] ) ) {
					if ( $_GET['action'] == 'list' ) {
						cng_list_galleries($galleries_to_convert);
					} elseif ( $_GET['action'] == 'convert' ) {
						cng_convert_galleries($galleries_to_convert);
					}
				} else {
					echo '<h3>' . count($galleries_to_convert) . ' galleries to convert</h3>';
				}
			?>
			<p><a class="" href="<?php echo cng_admin_url() . '&amp;action=list' ?>">List galleries to convert</a></p>
			<p><a class="button" href="<?php echo cng_admin_url() . '&amp;action=convert' ?>">Convert all galleries</a></p>
		</div>  
<?php
	});
});
