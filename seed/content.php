<?php
/**
 * ePHPm showcase content generator — runs INSIDE ePHPm so every write goes
 * through the db-wordpress drop-in -> Turso. Token-gated. Batchable.
 *
 *   ?k=TOKEN&a=taxonomy          create categories + tags
 *   ?k=TOKEN&a=authors           create extra authors
 *   ?k=TOKEN&a=posts&n=30&img=1  create N posts (with GD featured images if img=1)
 *   ?k=TOKEN&a=comments&n=120     add N comments spread over recent posts
 *   ?k=TOKEN&a=pages             create ~15 pages
 *   ?k=TOKEN&a=menu              build a primary nav menu
 */
define('SC_TOKEN', (string) getenv('EPHPM_SEED_TOKEN'));
if (SC_TOKEN === '') { http_response_code(403); exit("EPHPM_SEED_TOKEN not set
"); }
if (!hash_equals(SC_TOKEN, (string) ($_REQUEST['k'] ?? ''))) { http_response_code(403); exit("forbidden\n"); }

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
define('WP_USE_THEMES', false);
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
header('Content-Type: application/json');
global $wpdb;
$wpdb->hide_errors();

function jout($d){ echo json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit; }

// ---- word banks -----------------------------------------------------------
$ADJ = ['Scalable','Embedded','Resilient','Async','Zero-Copy','Distributed','Native','Lightweight','Hardened','Ephemeral','Multi-Tenant','Serverless','Reactive','Immutable','Observable','Elastic','Composable','Streaming','Concurrent','Idempotent'];
$NOUN = ['Runtime','Cluster','Gateway','Pipeline','Cache','Scheduler','Registry','Mesh','Kernel','Protocol','Datastore','Router','Namespace','Sidecar','Payload','Snapshot','Workload','Replica','Frontend','Backend'];
$VERB = ['Rethinking','Benchmarking','Scaling','Debugging','Shipping','Profiling','Hardening','Migrating','Automating','Deploying','Observing','Tuning','Refactoring','Securing','Orchestrating'];
$TOPIC = ['PHP on the Edge','SQLite at Scale','Rust FFI Safety','Gossip Clustering','WebSocket Fan-out','Object Caching','Query Translation','Zero-Downtime Deploys','Connection Pooling','Multi-Tenant Isolation','Turso Replication','Load Shedding','Crash Containment','Wire Protocols','Static Binaries'];
$WORDS = explode(' ', 'system request thread latency throughput socket buffer worker tenant cache query index schema replica primary gossip cluster payload frame channel session token digest metric histogram counter bucket protocol handshake upgrade backend frontend runtime kernel pointer lifetime borrow mutex atomic pool spawn dispatch route render template hook filter option transient migration snapshot journal checkpoint durable commit rollback isolation vhost docroot binary embed native async await future stream poll ready shed contain overflow retire poison');

function sentence($n=null){ global $WORDS; $n=$n?:rand(8,18); $s=[]; for($i=0;$i<$n;$i++){$s[]=$WORDS[array_rand($WORDS)];} $t=implode(' ',$s); return ucfirst($t).'.'; }
function paragraph(){ $n=rand(3,6); $p=[]; for($i=0;$i<$n;$i++)$p[]=sentence(); return implode(' ',$p); }
function body_html(){
    $parts=[];
    $parts[]='<p>'.paragraph().'</p>';
    $parts[]='<h2>'.ucfirst(sentence(rand(3,5))).'</h2>';
    $parts[]='<p>'.paragraph().'</p>';
    $parts[]='<ul>'.implode('', array_map(fn()=>'<li>'.rtrim(sentence(rand(4,9)),'.').'</li>', range(1,rand(3,5)))).'</ul>';
    $parts[]='<p>'.paragraph().'</p>';
    $parts[]='<blockquote><p>'.sentence(rand(10,16)).'</p></blockquote>';
    $parts[]='<p>'.paragraph().'</p>';
    return implode("\n", $parts);
}
function title(){ global $ADJ,$NOUN,$VERB,$TOPIC; $r=rand(0,2);
    if($r==0) return $VERB[array_rand($VERB)].' the '.$ADJ[array_rand($ADJ)].' '.$NOUN[array_rand($NOUN)];
    if($r==1) return $ADJ[array_rand($ADJ)].' '.$NOUN[array_rand($NOUN)].': '.$TOPIC[array_rand($TOPIC)];
    return 'How We Cut '.$NOUN[array_rand($NOUN)].' '.['Latency','Cost','Memory','Downtime'][rand(0,3)].' by '.rand(20,90).'%';
}
function rand_past_date(){ $days=rand(1,760); $ts=time()-$days*86400-rand(0,86399); return date('Y-m-d H:i:s',$ts); }

$CATS = [
 'Engineering'=>'Deep dives into systems, runtimes, and the guts of the stack.',
 'Performance'=>'Benchmarks, profiling, and the war on latency.',
 'Databases'=>'SQLite, Turso, wire protocols, and query translation.',
 'Rust'=>'Memory safety, FFI, and fearless concurrency.',
 'PHP'=>'The language that refuses to die, embedded and fast.',
 'DevOps'=>'Deploys, clusters, and keeping the lights on.',
 'Security'=>'Isolation, hardening, and multi-tenant boundaries.',
 'Tutorials'=>'Step-by-step guides for building real things.',
 'Product'=>'Release notes, roadmaps, and what shipped.',
 'Culture'=>'How the team works, thinks, and argues about tabs.',
];
$FIRST=['Ada','Grace','Linus','Dennis','Ken','Margaret','Alan','Barbara','Rob','Anita','Guido','Bjarne','Radia','Katherine','Hedy','Tim','Vint','Leslie','Donald','Frances'];
$LAST=['Lovelace','Hopper','Torvalds','Ritchie','Thompson','Hamilton','Turing','Liskov','Pike','Borg','Rossum','Stroustrup','Perlman','Johnson','Lamarr','Berners-Lee','Cerf','Lamport','Knuth','Allen'];

$a = $_REQUEST['a'] ?? 'info';
$rep = ['a'=>$a];

if ($a==='taxonomy') {
    global $CATS,$ADJ,$NOUN,$TOPIC;
    $made=[];
    foreach($CATS as $name=>$desc){
        $t=term_exists($name,'category'); if(!$t){ $t=wp_insert_term($name,'category',['description'=>$desc]); }
        if(!is_wp_error($t)) $made[$name]=is_array($t)?$t['term_id']:$t;
    }
    // tags
    $tags=[]; foreach($ADJ as $x)$tags[]=$x; foreach($NOUN as $x)$tags[]=$x; foreach(['turso','litewire','ffi','wasm','http2','tls','acme','opcache','ztd','swim','cdc','hrana','pdo','resp','gossip','sapi','zts','jit','wal','vhost'] as $x)$tags[]=$x;
    $tc=0; foreach(array_unique($tags) as $tg){ if(!term_exists($tg,'post_tag')){ $r=wp_insert_term($tg,'post_tag'); if(!is_wp_error($r))$tc++; } }
    if($wpdb->last_error) $rep['last_error']=$wpdb->last_error;
    $rep['categories']=$made; $rep['tags_created']=$tc; jout($rep);
}

if ($a==='authors') {
    global $FIRST,$LAST; $made=[];
    for($i=0;$i<6;$i++){
        $fn=$FIRST[array_rand($FIRST)]; $ln=$LAST[array_rand($LAST)];
        $login=strtolower($fn.'.'.$ln.rand(1,99)); if(username_exists($login))continue;
        $uid=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(20),'display_name'=>"$fn $ln",'first_name'=>$fn,'last_name'=>$ln,'user_email'=>$login.'@example.invalid','role'=>'author','description'=>'Writes about '.['systems','performance','databases','Rust','PHP'][rand(0,4)].'.']);
        if(!is_wp_error($uid))$made[]=$login;
    }
    if($wpdb->last_error) $rep['last_error']=$wpdb->last_error;
    $rep['authors']=$made; jout($rep);
}

if ($a==='posts') {
    $n=max(1,min(60,(int)($_REQUEST['n']??30)));
    $img=(int)($_REQUEST['img']??1);
    $catIds=get_terms(['taxonomy'=>'category','hide_empty'=>false,'fields'=>'ids']);
    $tagIds=get_terms(['taxonomy'=>'post_tag','hide_empty'=>false,'fields'=>'ids']);
    $authors=get_users(['fields'=>'ID']);
    if(!$catIds || is_wp_error($catIds)) $catIds=[1];
    $created=[]; $errs=[];
    $gd = function_exists('imagecreatetruecolor');
    for($i=0;$i<$n;$i++){
        $ttl=title();
        $date=rand_past_date();
        $pid=wp_insert_post([
            'post_title'=>$ttl,
            'post_content'=>body_html(),
            'post_excerpt'=>sentence(rand(14,22)),
            'post_status'=>'publish',
            'post_author'=>$authors[array_rand($authors)],
            'post_date'=>$date,'post_date_gmt'=>$date,
            'post_type'=>'post',
            'comment_status'=>'open',
        ], true);
        if(is_wp_error($pid)){ $errs[]=$pid->get_error_message(); continue; }
        // terms
        $pc=[]; $k=rand(1,2); for($j=0;$j<$k;$j++)$pc[]=$catIds[array_rand($catIds)];
        wp_set_post_terms($pid,array_values(array_unique($pc)),'category');
        if($tagIds && !is_wp_error($tagIds)){ $pt=[]; $k=rand(2,5); for($j=0;$j<$k;$j++)$pt[]=$tagIds[array_rand($tagIds)]; wp_set_post_terms($pid,array_values(array_unique($pt)),'post_tag'); }
        // featured image via GD
        if($img && $gd){
            $aid=gen_featured_image($pid,$ttl);
            if($aid && !is_wp_error($aid)) set_post_thumbnail($pid,$aid);
        }
        $created[]=$pid;
    }
    if($wpdb->last_error) $rep['last_error']=$wpdb->last_error;
    $rep['created']=count($created); $rep['ids']=[min($created?:[0]),max($created?:[0])];
    if($errs)$rep['errors']=array_slice(array_unique($errs),0,10);
    jout($rep);
}

function gen_featured_image($pid,$ttl){
    $up=wp_upload_dir();
    if(!empty($up['error'])) return 0;
    $W=1200;$H=630;
    $im=imagecreatetruecolor($W,$H);
    // gradient background from a hashed hue
    $h=crc32($ttl); $r1=($h)&0x7f; $g1=($h>>7)&0x7f; $b1=($h>>14)&0x7f;
    $cl=fn($v)=>max(0,min(255,(int)$v));
    for($y=0;$y<$H;$y++){ $t=$y/$H; $c=imagecolorallocate($im,$cl($r1+$t*60),$cl($g1+30+$t*40),$cl($b1+80+$t*60)); imageline($im,0,$y,$W,$y,$c); }
    $white=imagecolorallocate($im,245,245,250);
    $accent=imagecolorallocate($im,255,255,255);
    // title text (wrapped) with built-in font
    $words=explode(' ',$ttl); $line='';$lines=[];
    foreach($words as $w){ if(strlen($line.' '.$w)>28){$lines[]=trim($line);$line=$w;}else{$line.=' '.$w;} }
    if(trim($line))$lines[]=trim($line);
    $fy=220; foreach(array_slice($lines,0,4) as $ln){ imagestring($im,5,80,$fy,$ln,$white); $fy+=40; }
    imagestring($im,3,80,$H-60,'ePHPm Preview — '.date('Y'),$accent);
    imagefilledrectangle($im,0,0,$W,10,$accent);
    $fn='featured-'.$pid.'-'.substr(md5($ttl),0,6).'.png';
    $path=$up['path'].'/'.$fn;
    imagepng($im,$path); imagedestroy($im);
    $ft=wp_check_filetype($fn,null);
    $aid=wp_insert_attachment(['post_mime_type'=>$ft['type'],'post_title'=>$ttl,'post_content'=>'','post_status'=>'inherit'],$path,$pid);
    if(is_wp_error($aid)||!$aid)return 0;
    $meta=wp_generate_attachment_metadata($aid,$path);
    wp_update_attachment_metadata($aid,$meta);
    return $aid;
}

if ($a==='comments') {
    $n=max(1,min(400,(int)($_REQUEST['n']??120)));
    global $FIRST,$LAST;
    $posts=get_posts(['numberposts'=>200,'post_status'=>'publish','fields'=>'ids']);
    if(!$posts){ jout(['error'=>'no posts']); }
    $made=0;$errs=[];
    for($i=0;$i<$n;$i++){
        $pid=$posts[array_rand($posts)];
        $fn=$FIRST[array_rand($FIRST)]; $ln=$LAST[array_rand($LAST)];
        $pd=get_post($pid); $base=strtotime($pd->post_date); $cd=date('Y-m-d H:i:s',$base+rand(3600,86400*20));
        $cid=wp_insert_comment([
            'comment_post_ID'=>$pid,
            'comment_author'=>"$fn $ln",
            'comment_author_email'=>strtolower($fn.'.'.$ln).'@example.invalid',
            'comment_content'=>rand(0,1)?sentence(rand(6,14)):paragraph(),
            'comment_date'=>$cd,'comment_date_gmt'=>$cd,
            'comment_approved'=>1,
        ]);
        if($cid)$made++; else if($wpdb->last_error){$errs[]=$wpdb->last_error;}
    }
    // fix comment counts
    $posts2=array_unique($posts);
    foreach($posts2 as $pid){ wp_update_comment_count($pid); }
    if($wpdb->last_error) $rep['last_error']=$wpdb->last_error;
    $rep['comments_created']=$made; if($errs)$rep['errors']=array_slice(array_unique($errs),0,10);
    jout($rep);
}

if ($a==='pages') {
    $pages=[
      'About'=>'<p>'.paragraph().'</p><p>'.paragraph().'</p>',
      'Contact'=>'<p>Reach the team. '.paragraph().'</p>',
      'Documentation'=>'<h2>Getting Started</h2><p>'.paragraph().'</p><h2>Configuration</h2><p>'.paragraph().'</p>',
      'Roadmap'=>'<p>'.paragraph().'</p><ul><li>'.sentence(6).'</li><li>'.sentence(6).'</li><li>'.sentence(6).'</li></ul>',
      'Pricing'=>'<p>'.paragraph().'</p>',
      'Careers'=>'<p>'.paragraph().'</p>',
      'Privacy Policy'=>'<p>'.paragraph().'</p>',
      'Terms of Service'=>'<p>'.paragraph().'</p>',
      'FAQ'=>'<h3>Is it fast?</h3><p>'.sentence(12).'</p><h3>Does it scale?</h3><p>'.sentence(12).'</p>',
      'Team'=>'<p>'.paragraph().'</p>',
      'Press'=>'<p>'.paragraph().'</p>',
      'Changelog'=>'<p>'.paragraph().'</p>',
      'Case Studies'=>'<p>'.paragraph().'</p>',
      'Support'=>'<p>'.paragraph().'</p>',
      'Community'=>'<p>'.paragraph().'</p>',
    ];
    $made=[];
    foreach($pages as $t=>$c){
        $ex=get_page_by_path(sanitize_title($t));
        if($ex)continue;
        $pid=wp_insert_post(['post_title'=>$t,'post_content'=>$c,'post_status'=>'publish','post_type'=>'page']);
        if(!is_wp_error($pid))$made[$t]=$pid;
    }
    if($wpdb->last_error) $rep['last_error']=$wpdb->last_error;
    $rep['pages']=$made; jout($rep);
}

if ($a==='menu') {
    $name='Primary';
    $menu=wp_get_nav_menu_object($name);
    if(!$menu){ $mid=wp_create_nav_menu($name); } else { $mid=$menu->term_id; }
    if(is_wp_error($mid)) jout(['error'=>$mid->get_error_message()]);
    // clear existing items
    $items=wp_get_nav_menu_items($mid); if($items){ foreach($items as $it) wp_delete_post($it->ID,true); }
    $order=1;
    // categories
    $cats=get_terms(['taxonomy'=>'category','hide_empty'=>false,'number'=>6]);
    foreach($cats as $c){ wp_update_nav_menu_item($mid,0,['menu-item-title'=>$c->name,'menu-item-type'=>'taxonomy','menu-item-object'=>'category','menu-item-object-id'=>$c->term_id,'menu-item-status'=>'publish','menu-item-position'=>$order++]); }
    // key pages
    foreach(['About','Documentation','Roadmap','Contact'] as $slug){ $p=get_page_by_path(sanitize_title($slug)); if($p){ wp_update_nav_menu_item($mid,0,['menu-item-title'=>$p->post_title,'menu-item-type'=>'post_type','menu-item-object'=>'page','menu-item-object-id'=>$p->ID,'menu-item-status'=>'publish','menu-item-position'=>$order++]); } }
    // assign to theme locations
    $locs=get_registered_nav_menus();
    $set=get_theme_mod('nav_menu_locations',[]);
    if($locs){ foreach(array_keys($locs) as $loc){ $set[$loc]=$mid; } set_theme_mod('nav_menu_locations',$set); }
    if($wpdb->last_error) $rep['last_error']=$wpdb->last_error;
    $rep['menu_id']=$mid; $rep['locations']=array_keys($locs?:[]); jout($rep);
}

jout(['error'=>'unknown action','a'=>$a]);
