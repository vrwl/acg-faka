<?php
/* Smarty version 3.1.46, created on 2026-09-18 18:34:52
  from '/workspace/app/View/User/Theme/Cartoon/Index/Header.html' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '3.1.46',
  'unifunc' => 'content_6aad13cc9911b7_06156241',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '24911cb70ccb77b8017d605c1c6c174a699240a2' => 
    array (
      0 => '/workspace/app/View/User/Theme/Cartoon/Index/Header.html',
      1 => 1789726831,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6aad13cc9911b7_06156241 (Smarty_Internal_Template $_smarty_tpl) {
?><!DOCTYPE html>
<html lang="<?php echo lang_code();?>
">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
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
    <?php echo css(array("/assets/common/css/bootstrap.min.css","/assets/common/css/_.css","/assets/user/css/index.css"),array("/assets/common/css/font.min.css","/assets/common/js/layui/css/layui.css","/assets/common/css/select2.min.css","/assets/common/css/component.css","/assets/common/js/table/bootstrap-table.css","/assets/common/js/layer/theme/default/layer.css","/assets/common/css/bootstrap.min.css","/assets/common/css/toastr.min.css","/assets/user/css/index.css"));?>

    <?php echo js("/assets/common/js/ready.js");?>

    <?php echo index_var();?>

    <!--start::HOOK-->
    <?php echo hook(\App\Consts\Hook::USER_GLOBAL_VIEW_HEADER);?>

    <?php echo hook(\App\Consts\Hook::USER_VIEW_INDEX_HEADER);?>

    <!--end::HOOK-->
</head>
<body style="background-size: cover;background-image: linear-gradient(180deg, rgb(255 255 255 / 0%), rgb(255 255 255 / 71%)), url('<?php echo $_smarty_tpl->tpl_vars['config']->value['background_url'];?>
')">
<nav class="navbar navbar-expand-lg navbar-acg">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="/">
            <img src="/favicon.ico" alt="ACG Logo" class="brand-logo me-2">
            <span style="color: #1396558a;"><?php echo $_smarty_tpl->tpl_vars['config']->value['shop_name'];?>
</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-lg-0">
                <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, user_header_nav(), 'nav');
$_smarty_tpl->tpl_vars['nav']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['nav']->value) {
$_smarty_tpl->tpl_vars['nav']->do_else = false;
?>
                    <li class="nav-item"><a class="nav-link <?php if ($_smarty_tpl->tpl_vars['nav']->value['match']) {
echo active($_smarty_tpl->tpl_vars['nav']->value['match']);
}?>"
                                            href="<?php echo $_smarty_tpl->tpl_vars['nav']->value['url'];?>
" target="<?php echo $_smarty_tpl->tpl_vars['nav']->value['target'];?>
"><?php echo user_nav_icon($_smarty_tpl->tpl_vars['nav']->value,'nav-icon');
echo $_smarty_tpl->tpl_vars['nav']->value['name'];?>
</a></li>
                <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
            </ul>
            <div class="d-none d-lg-flex search-input" role="search">
                <div class="input-group">
                    <span class="input-group-text"><i
                                class="fa-duotone fa-regular fa-magnifying-glass nav-icon"></i></span>
                    <input class="form-control item-search-input" type="search" placeholder="<?php echo t('搜索商品关键词..');?>
"
                           aria-label="Search">
                </div>
            </div>
        </div>

        <div class="ms-2 dropdown lang-switch">
            <button class="btn lang-switch__btn" type="button" id="langDropdown"
                    data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo t('语言');?>
">
                <i class="fa-duotone fa-regular fa-globe"></i>
                <span class="lang-switch__code" data-lang-label></span>
                <i class="fa-duotone fa-regular fa-chevron-down lang-switch__caret"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end lang-switch__menu" aria-labelledby="langDropdown">
                <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['langs']->value, 'l');
$_smarty_tpl->tpl_vars['l']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['l']->value) {
$_smarty_tpl->tpl_vars['l']->do_else = false;
?>
                    <li><a class="dropdown-item" href="javascript:;" data-lang-value="<?php echo $_smarty_tpl->tpl_vars['l']->value['code'];?>
"><?php echo $_smarty_tpl->tpl_vars['l']->value['name'];?>
</a></li>
                <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
            </ul>
        </div>

        <?php if ($_smarty_tpl->tpl_vars['user']->value) {?>
            <div class="ms-2 user-info-box">
                <div class="dropdown">
                    <button class="btn btn-link text-decoration-none dropdown-toggle d-flex align-items-center"
                            type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <img id="user-avatar"
                             src="<?php echo $_smarty_tpl->tpl_vars['user']->value['avatar'];?>
"
                             alt="<?php echo t('用户头像');?>
" class="rounded-circle me-2"
                             style="width: 32px; height: 32px; object-fit: cover; background-color: #f8f9fa;">
                        <div class="d-flex flex-column align-items-start me-2">
                                <span id="username" class="fw-bold text-dark"
                                      style="font-size: 14px; line-height: 1.2;"><?php echo $_smarty_tpl->tpl_vars['user']->value['username'];?>
</span>
                            <span id="user-balance" class="text-muted" style="font-size: 12px; line-height: 1.2;"><?php echo t("余额:");?>
 <span
                                        class="text-success"><?php echo $_smarty_tpl->tpl_vars['config']->value['currency_symbol'];
echo $_smarty_tpl->tpl_vars['user']->value['balance'];?>
</span></span>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="/user/dashboard/index"><i
                                        class="fa-duotone fa-regular fa-user me-2"></i><?php echo t("个人中心");?>
</a></li>
                        <li><a class="dropdown-item" href="/user/recharge/index"><i
                                        class="fa-duotone fa-regular fa-wallet me-2"></i><?php echo t("钱包充值");?>
</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="/user/personal/purchaseRecord"><i
                                        class="fa-duotone fa-regular fa-receipt me-2"></i><?php echo t("我的订单");?>
</a></li>
                        <li><a class="dropdown-item" href="/user/security/personal"><i
                                        class="fa-duotone fa-regular fa-gear me-2"></i><?php echo t("设置");?>
</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger" href="/user/authentication/logout"><i
                                        class="fa-duotone fa-regular fa-sign-out me-2"></i><?php echo t("退出登录");?>
</a></li>
                    </ul>
                </div>
            </div>
        <?php } else { ?>
            <div class="ms-2 user-login-box">
                <a class="btn btn-outline-secondary btn-sm br-12" href="/user/authentication/login"><i
                            class="fa-duotone fa-regular fa-right-to-bracket nav-icon"></i><?php echo t("登录");?>
</a>
                <a class="btn btn-primary btn-sm br-12" href="/user/authentication/register"><i
                            class="fa-duotone fa-regular fa-user-plus nav-icon"></i><?php echo t("创建账号");?>
</a>
            </div>
        <?php }?>

    </div>
</nav>
<div id="pjax-container"><?php }
}
