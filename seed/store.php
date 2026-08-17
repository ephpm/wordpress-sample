<?php
/** WooCommerce sample-store generator — runs inside ePHPm through the drop-in.
 *  ?k=TOKEN&a=cats            create product categories
 *  ?k=TOKEN&a=products&n=40   create N simple products (with GD images)
 *  ?k=TOKEN&a=orders&n=6      create N sample orders
 *  ?k=TOKEN&a=pages           ensure shop/cart/checkout/my-account pages
 */
define('SC_TOKEN', (string) getenv('EPHPM_SEED_TOKEN'));
if (SC_TOKEN === '') { http_response_code(403); exit("EPHPM_SEED_TOKEN not set\n"); }
if(!hash_equals(SC_TOKEN,(string)($_REQUEST['k']??''))){http_response_code(403);exit("forbidden\n");}
@set_time_limit(0); @ini_set('memory_limit','1024M');
define('WP_USE_THEMES',false);
require __DIR__.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/taxonomy.php';
require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/media.php';
header('Content-Type: application/json');
global $wpdb;
function jout($d){ echo json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit; }
if(!class_exists('WC_Product_Simple')){ jout(['error'=>'WooCommerce not active']); }

$CATS=['Apparel'=>'Wearable ePHPm merch and threads.','Electronics'=>'Gadgets that run on the edge.','Books'=>'Reading for systems people.','Home & Office'=>'Desk gear for builders.','Accessories'=>'The finishing touches.','Outdoors'=>'For hacking away from the desk.'];
$ADJ=['Turbo','Edge','Async','Quantum','Zero','Hyper','Neo','Pixel','Cloud','Mesh','Vertex','Photon','Cobalt','Ember','Nimbus'];
$NOUN=['Mug','Tee','Hoodie','Cap','Sticker Pack','Notebook','Keyboard','Mouse','Bottle','Backpack','Socks','Poster','Lamp','Cable','Charger','Pin Set','Tote','Beanie','Desk Mat','Coaster'];
$WORDS=explode(' ','durable premium lightweight ergonomic minimal rugged sleek portable limited signature classic modern handcrafted sustainable performance');

function words($n){global $WORDS;$s=[];for($i=0;$i<$n;$i++)$s[]=$WORDS[array_rand($WORDS)];return implode(' ',$s);}

function gen_img($name){
    if(!function_exists('imagepng'))return 0;
    $up=wp_upload_dir(); if(!empty($up['error']))return 0;
    $W=800;$H=800; $im=imagecreatetruecolor($W,$H);
    $h=crc32($name); $cl=fn($v)=>max(0,min(255,(int)$v));
    $r=$cl(($h)&0xff);$g=$cl(($h>>8)&0xff);$b=$cl(($h>>16)&0xff);
    for($y=0;$y<$H;$y++){$t=$y/$H;$c=imagecolorallocate($im,$cl($r*(1-$t)+255*$t),$cl($g*(1-$t)+255*$t),$cl($b*(1-$t)+255*$t));imageline($im,0,$y,$W,$y,$c);}
    $dark=imagecolorallocate($im,20,20,30);
    imagefilledellipse($im,$W/2,$H/2-30,360,360,imagecolorallocatealpha($im,255,255,255,60));
    imagestring($im,5,60,$H-90,substr($name,0,40),$dark);
    $fn='product-'.substr(md5($name.mt_rand()),0,8).'.png'; $path=$up['path'].'/'.$fn;
    imagepng($im,$path); imagedestroy($im);
    $aid=wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>$name,'post_status'=>'inherit'],$path);
    if(is_wp_error($aid)||!$aid)return 0;
    wp_update_attachment_metadata($aid,wp_generate_attachment_metadata($aid,$path));
    return $aid;
}

$a=$_REQUEST['a']??'';

if($a==='cats'){
    global $CATS; $made=[];
    foreach($CATS as $n=>$d){ $t=term_exists($n,'product_cat'); if(!$t){$t=wp_insert_term($n,'product_cat',['description'=>$d]);} if(!is_wp_error($t))$made[$n]=is_array($t)?$t['term_id']:$t; }
    if($wpdb->last_error)$made['last_error']=$wpdb->last_error;
    jout(['cats'=>$made]);
}

if($a==='pages'){
    // Let WooCommerce install its pages (shop/cart/checkout/my-account).
    if(class_exists('WC_Install')){ WC_Install::create_pages(); }
    $ids=['shop'=>wc_get_page_id('shop'),'cart'=>wc_get_page_id('cart'),'checkout'=>wc_get_page_id('checkout'),'myaccount'=>wc_get_page_id('myaccount')];
    jout(['pages'=>$ids]);
}

if($a==='products'){
    global $ADJ,$NOUN; $n=max(1,min(60,(int)($_REQUEST['n']??40)));
    $cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'fields'=>'ids']);
    if(!$cats||is_wp_error($cats))$cats=[];
    $img=(int)($_REQUEST['img']??1);
    $made=[]; $errs=[];
    for($i=0;$i<$n;$i++){
        $name=$ADJ[array_rand($ADJ)].' '.$NOUN[array_rand($NOUN)];
        try{
            $p=new WC_Product_Simple();
            $p->set_name($name);
            $p->set_status('publish');
            $p->set_catalog_visibility('visible');
            $price=mt_rand(9,199)+0.99;
            $p->set_regular_price((string)$price);
            if(mt_rand(0,3)===0){ $p->set_sale_price((string)round($price*0.8,2)); }
            $p->set_description('<p>'.ucfirst(words(mt_rand(20,40))).'</p><ul><li>'.words(4).'</li><li>'.words(4).'</li><li>'.words(4).'</li></ul>');
            $p->set_short_description(ucfirst(words(mt_rand(8,14))).'.');
            $p->set_manage_stock(false);
            $p->set_stock_status('instock');
            $p->set_sku('EPHPM-'.strtoupper(substr(md5($name.$i),0,8)));
            if($cats){ $p->set_category_ids([$cats[array_rand($cats)]]); }
            if($img){ $aid=gen_img($name); if($aid)$p->set_image_id($aid); }
            $id=$p->save();
            if($id)$made[]=$id; else $errs[]='save returned 0';
        }catch(\Throwable $e){ $errs[]=$e->getMessage(); }
    }
    if($wpdb->last_error)$errs[]='wpdb:'.$wpdb->last_error;
    jout(['created'=>count($made),'ids'=>$made?[min($made),max($made)]:[],'errors'=>array_slice(array_unique($errs),0,8)]);
}

if($a==='orders'){
    $n=max(1,min(30,(int)($_REQUEST['n']??6)));
    $prods=wc_get_products(['limit'=>50,'status'=>'publish','return'=>'ids']);
    if(!$prods){ jout(['error'=>'no products']); }
    global $FIRST,$LAST;
    $F=['Ada','Grace','Linus','Ken','Margaret','Alan','Rob','Anita','Guido','Radia'];
    $L=['Lovelace','Hopper','Torvalds','Ritchie','Hamilton','Turing','Pike','Perlman','Rossum','Lamport'];
    $made=[]; $errs=[];
    for($i=0;$i<$n;$i++){
        try{
            $order=wc_create_order();
            $k=mt_rand(1,3);
            for($j=0;$j<$k;$j++){ $order->add_product(wc_get_product($prods[array_rand($prods)]), mt_rand(1,3)); }
            $fn=$F[array_rand($F)];$ln=$L[array_rand($L)];
            $addr=['first_name'=>$fn,'last_name'=>$ln,'email'=>strtolower($fn.'.'.$ln).'@example.invalid','address_1'=>mt_rand(1,999).' Loopback Ave','city'=>'Localhost','state'=>'CA','postcode'=>'94000','country'=>'US'];
            $order->set_address($addr,'billing'); $order->set_address($addr,'shipping');
            $order->calculate_totals();
            $order->update_status(['pending','processing','completed','completed','on-hold'][array_rand([0,1,2,3,4])]);
            $made[]=$order->get_id();
        }catch(\Throwable $e){ $errs[]=$e->getMessage(); }
    }
    if($wpdb->last_error)$errs[]='wpdb:'.$wpdb->last_error;
    jout(['created'=>count($made),'ids'=>$made,'errors'=>array_slice(array_unique($errs),0,8)]);
}

jout(['error'=>'unknown action','a'=>$a]);
