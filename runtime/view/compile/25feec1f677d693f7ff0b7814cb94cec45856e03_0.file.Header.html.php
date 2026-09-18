<?php
/* Smarty version 3.1.46, created on 2026-09-18 19:00:06
  from '/workspace/app/View/User/Theme/Cartoon/Authentication/Header.html' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '3.1.46',
  'unifunc' => 'content_6aad19b619f3a6_19283215',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '25feec1f677d693f7ff0b7814cb94cec45856e03' => 
    array (
      0 => '/workspace/app/View/User/Theme/Cartoon/Authentication/Header.html',
      1 => 1789726831,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6aad19b619f3a6_19283215 (Smarty_Internal_Template $_smarty_tpl) {
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="keywords" content="<?php echo $_smarty_tpl->tpl_vars['config']->value['keywords'];?>
"/>
    <meta name="description" content="<?php echo $_smarty_tpl->tpl_vars['config']->value['description'];?>
"/>
    <link href="<?php echo $_smarty_tpl->tpl_vars['favicon']->value;?>
?v=<?php echo $_smarty_tpl->tpl_vars['app']->value['version'];?>
" rel="icon">
    <title><?php echo $_smarty_tpl->tpl_vars['title']->value;?>
 - <?php echo $_smarty_tpl->tpl_vars['config']->value['shop_name'];?>
</title>
    <?php echo css(array("/assets/common/css/bootstrap.min.css","/assets/common/css/_.css","/assets/user/css/_auth.css"),array("/assets/common/css/font.min.css","/assets/common/js/layui/css/layui.css","/assets/common/css/select2.min.css","/assets/common/css/component.css","/assets/common/js/table/bootstrap-table.css","/assets/common/js/layer/theme/default/layer.css","/assets/common/css/bootstrap.min.css","/assets/common/css/toastr.min.css","/assets/user/css/auth.css"));?>

    <?php echo js("/assets/common/js/ready.js");?>

    <?php echo index_var();?>


    <!--start::HOOK-->
    <?php echo hook(\App\Consts\Hook::USER_GLOBAL_VIEW_HEADER);?>

    <!--end::HOOK-->
</head>

<body style="background-size: cover;background-image: linear-gradient(180deg, rgb(255 255 255 / 0%), rgb(255 255 255 / 71%)), url('<?php echo $_smarty_tpl->tpl_vars['config']->value['background_url'];?>
')"><?php }
}
