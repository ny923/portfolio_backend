<?php

/**
 * Plugin Name: Property Image Gallery with Comments
 * Description: カスタム投稿タイプ「property」に画像の繰り返しフィールド（コメント入力・並び替え付き）を追加します。
 * Version: 1.2
 * Author: with AI
 */

if (! defined('ABSPATH')) exit;

class Property_Gallery_Meta_Box
{

  public function __construct()
  {
    add_action('add_meta_boxes', array($this, 'add_meta_box'));
    add_action('save_post', array($this, 'save_meta_box'));
    add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
  }

  public function add_meta_box()
  {
    add_meta_box(
      'property_gallery',
      '物件ギャラリー画像',
      array($this, 'render_meta_box'),
      'property',
      'normal',
      'high'
    );
  }

  public function enqueue_assets()
  {
    $screen = get_current_screen();
    if (! $screen || $screen->post_type !== 'property') return;

    wp_enqueue_media();
    wp_enqueue_script('jquery-ui-sortable');

    wp_add_inline_style('wp-admin', '
            #property-gallery-container { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; background: #f0f0f1; padding: 15px; border: 1px dashed #ccc; border-radius: 4px; min-height: 100px; }
            .gallery-item { width: 140px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,0.04); position: relative; cursor: move; padding: 5px; }
            .gallery-item .image-preview { width: 100%; height: 120px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #eee; margin-bottom: 5px; }
            .gallery-item img { max-width: 100%; max-height: 100%; object-fit: cover; }
            .gallery-item .comment-field { width: 100%; border: 1px solid #ddd; border-radius: 2px; font-size: 11px; padding: 3px; box-sizing: border-box; }
            .gallery-item .remove-image { 
                position: absolute; top: -8px; right: -8px; background: #ff4d4d; color: white; 
                border-radius: 50%; width: 22px; height: 22px; text-align: center; 
                line-height: 20px; font-size: 16px; cursor: pointer; font-weight: bold; border: 2px solid #fff; z-index: 10;
            }
            .gallery-item .remove-image:hover { background: red; }
            #add-property-image { margin-top: 10px; }
            .ui-state-highlight { width: 140px; height: 160px; border: 2px dashed #ccc; background: #e5e5e5; }
        ');
  }

  public function render_meta_box($post)
  {
    // 保存されたデータを取得
    $gallery_data = get_post_meta($post->ID, '_property_images', true) ?: array();
    wp_nonce_field('property_gallery_save', 'property_gallery_nonce');
?>
    <div id="property-gallery-container">
      <?php
      foreach ($gallery_data as $item) :
        // 互換性維持：古い形式（IDのみの配列）の場合はIDとして扱う
        $image_id = is_array($item) ? $item['id'] : $item;
        $comment = is_array($item) ? $item['comment'] : '';

        $img_data = wp_get_attachment_image_src($image_id, 'thumbnail');
        $img_url = $img_data ? $img_data[0] : wp_get_attachment_url($image_id);

        if ($img_url) : ?>
          <div class="gallery-item" data-id="<?php echo esc_attr($image_id); ?>">
            <span class="remove-image">&times;</span>
            <div class="image-preview">
              <img src="<?php echo esc_url($img_url); ?>">
            </div>
            <input type="hidden" name="property_gallery_ids[]" value="<?php echo esc_attr($image_id); ?>">
            <input type="text" name="property_gallery_comments[]" class="comment-field" placeholder="コメントを入力" value="<?php echo esc_attr($comment); ?>">
          </div>
      <?php endif;
      endforeach; ?>
    </div>
    <button type="button" class="button button-large" id="add-property-image">
      <span class="dashicons dashicons-images-alt2" style="vertical-align: middle; margin-top: 4px;"></span>
      画像を追加・選択
    </button>

    <script>
      jQuery(document).ready(function($) {
        var frame;
        $('#add-property-image').on('click', function(e) {
          e.preventDefault();
          if (frame) {
            frame.open();
            return;
          }

          frame = wp.media({
            title: '物件画像を選択',
            button: {
              text: 'ギャラリーに追加'
            },
            multiple: true
          });

          frame.on('select', function() {
            var selections = frame.state().get('selection');
            selections.map(function(attachment) {
              attachment = attachment.toJSON();
              var thumbUrl = (attachment.sizes && attachment.sizes.thumbnail) ?
                attachment.sizes.thumbnail.url :
                attachment.url;

              $('#property-gallery-container').append(
                '<div class="gallery-item" data-id="' + attachment.id + '">' +
                '<span class="remove-image">&times;</span>' +
                '<div class="image-preview"><img src="' + thumbUrl + '"></div>' +
                '<input type="hidden" name="property_gallery_ids[]" value="' + attachment.id + '">' +
                '<input type="text" name="property_gallery_comments[]" class="comment-field" placeholder="コメントを入力" value="">' +
                '</div>'
              );
            });
          });
          frame.open();
        });

        $(document).on('click', '.remove-image', function() {
          $(this).closest('.gallery-item').fadeOut(200, function() {
            $(this).remove();
          });
        });

        $('#property-gallery-container').sortable({
          placeholder: 'ui-state-highlight',
          forcePlaceholderSize: true,
          cursor: 'move'
        });
      });
    </script>
<?php
  }

  public function save_meta_box($post_id)
  {
    if (! isset($_POST['property_gallery_nonce']) || ! wp_verify_nonce($_POST['property_gallery_nonce'], 'property_gallery_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (isset($_POST['property_gallery_ids']) && is_array($_POST['property_gallery_ids'])) {
      $ids = $_POST['property_gallery_ids'];
      $comments = isset($_POST['property_gallery_comments']) ? $_POST['property_gallery_comments'] : array();

      $save_data = array();
      foreach ($ids as $index => $id) {
        $save_data[] = array(
          'id'      => intval($id),
          'comment' => sanitize_text_field($comments[$index])
        );
      }
      update_post_meta($post_id, '_property_images', $save_data);
    } else {
      delete_post_meta($post_id, '_property_images');
    }
  }
}

new Property_Gallery_Meta_Box();
