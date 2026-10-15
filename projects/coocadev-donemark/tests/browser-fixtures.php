<?php
/** Isolated synthetic fixtures for real browser interactions. */
$project=dirname(__DIR__);$root=dirname($project,2);require ($argv[1]??$root.'/qa/sites/donemark').'/wp-load.php';$report=$argv[2]??$project.'/reports/0.1.0';
wp_set_current_user(get_user_by('login','qa_owner')->ID);
$defs=[['id'=>'','label'=>'QA Review','type'=>'review','post_types'=>['post','page'],'archived'=>false],['id'=>'','label'=>'QA Distribution','type'=>'distribution','post_types'=>['post','page'],'archived'=>false]];
update_option('ccdm_settings',Coocadev\DoneMark\Settings::defaults(),false);$settings=Coocadev\DoneMark\Settings::save($defs,1,false);
$post=wp_insert_post(['post_title'=>'Browser DoneMark '.time(),'post_content'=>'Original browser content','post_excerpt'=>'Original excerpt','post_status'=>'publish','post_author'=>get_current_user_id()]);
$page=wp_insert_post(['post_type'=>'page','post_title'=>'Browser DoneMark page '.time(),'post_content'=>'Page body','post_status'=>'publish','post_author'=>get_current_user_id()]);
file_put_contents($report.'/browser-fixtures.json',json_encode(['post'=>$post,'page'=>$page,'review'=>$settings['definitions'][0]['id'],'distribution'=>$settings['definitions'][1]['id']]));
echo "Created synthetic browser post/page.\n";
