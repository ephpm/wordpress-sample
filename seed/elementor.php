<?php
/** Create a sample Elementor-built page through the drop-in. ?k=TOKEN */
define('SC_TOKEN', (string) getenv('EPHPM_SEED_TOKEN'));
if (SC_TOKEN === '') { http_response_code(403); exit("EPHPM_SEED_TOKEN not set\n"); }
if(!hash_equals(SC_TOKEN,(string)($_REQUEST['k']??''))){http_response_code(403);exit("forbidden\n");}
define('WP_USE_THEMES',false); require __DIR__.'/wp-load.php';
header('Content-Type: application/json');
if(!defined('ELEMENTOR_VERSION')){ jout(['error'=>'Elementor not active']); }
function jout($d){ echo json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit; }
function eid(){ return substr(md5(uniqid('',true)),0,7); }

function heading($t,$size='xxl',$align='center',$color='#ffffff'){
  return ['id'=>eid(),'elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>$t,'align'=>$align,'title_color'=>$color,'typography_typography'=>'custom','typography_font_size'=>['unit'=>'px','size'=>$size==='xxl'?46:28]]];
}
function textw($html,$align='center',$color='#e5e7eb'){
  return ['id'=>eid(),'elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>$html,'align'=>$align,'text_color'=>$color]];
}
function column($widgets,$size=100){
  return ['id'=>eid(),'elType'=>'column','settings'=>['_column_size'=>$size,'_inline_size'=>null],'elements'=>$widgets,'isInner'=>false];
}
function section($cols,$settings=[]){
  return ['id'=>eid(),'elType'=>'section','settings'=>$settings,'elements'=>$cols,'isInner'=>false];
}

$data=[
  section([ column([
      heading('Built with Elementor — on Embedded Turso','xxl'),
      textw('<p>This page was assembled with the Elementor page builder and stored in ePHPm’s in-process SQLite-compatible Turso database through the <code>db-wordpress</code> drop-in — no MySQL, no external database server.</p>'),
    ]) ], ['background_background'=>'classic','background_color'=>'#4f46e5','padding'=>['unit'=>'px','top'=>'70','bottom'=>'70','left'=>'20','right'=>'20','isLinked'=>false]]),
  section([
      column([ heading('Fast','md','center','#111827'), textw('<p>The Turso engine runs in the same process as PHP. Queries never leave the binary.</p>','center','#4b5563') ],33),
      column([ heading('Isolated','md','center','#111827'), textw('<p>Every preview vhost gets its own database file — a hard multi-tenant boundary.</p>','center','#4b5563') ],33),
      column([ heading('Live','md','center','#111827'), textw('<p>Native WebSockets fan out activity to every visitor in real time.</p>','center','#4b5563') ],34),
    ], ['padding'=>['unit'=>'px','top'=>'50','bottom'=>'50','left'=>'20','right'=>'20','isLinked'=>false]]),
  section([ column([
      heading('A page builder, working on Turso','md','center','#111827'),
      textw('<p>Elementor’s activation hooks, options and rendered layout all round-trip through the embedded engine. <a href="/shop/">Visit the shop »</a></p>'),
    ]) ], ['padding'=>['unit'=>'px','top'=>'40','bottom'=>'60','left'=>'20','right'=>'20','isLinked'=>false]]),
];

$existing=get_page_by_path('showcase-elementor');
$pid = $existing ? $existing->ID : wp_insert_post([
  'post_title'=>'Showcase (Elementor)',
  'post_name'=>'showcase-elementor',
  'post_status'=>'publish','post_type'=>'page','post_content'=>'',
]);
if(is_wp_error($pid)) jout(['error'=>$pid->get_error_message()]);

update_post_meta($pid,'_elementor_edit_mode','builder');
update_post_meta($pid,'_elementor_version',ELEMENTOR_VERSION);
update_post_meta($pid,'_wp_page_template','elementor_header_footer');
update_post_meta($pid,'_elementor_data',wp_slash(json_encode($data)));

// Ask Elementor to (re)generate this page's CSS if possible.
if(class_exists('\Elementor\Core\Files\CSS\Post')){
  try{ $css=new \Elementor\Core\Files\CSS\Post($pid); $css->update(); }catch(\Throwable $e){}
}

jout(['page_id'=>$pid,'url'=>get_permalink($pid),'sections'=>count($data)]);
