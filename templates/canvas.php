<?php defined('ABSPATH') || exit; ?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><?php wp_head(); ?></head>
<body class="ecm-canvas" dir="rtl"><?php wp_body_open(); ?><main><?php while(have_posts()):the_post();the_content();endwhile; ?></main><?php wp_footer(); ?></body></html>
