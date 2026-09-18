<?php
/* Smarty version 3.1.46, created on 2026-09-18 19:00:06
  from '/workspace/app/View/User/Theme/Cartoon/Authentication/Login.html' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '3.1.46',
  'unifunc' => 'content_6aad19b6193953_20147255',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'd6a33a5ee7cb5f7b9bc9fc5ba41e2132b884e017' => 
    array (
      0 => '/workspace/app/View/User/Theme/Cartoon/Authentication/Login.html',
      1 => 1789726831,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:./Header.html' => 1,
    'file:./Footer.html' => 1,
  ),
),false)) {
function content_6aad19b6193953_20147255 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_subTemplateRender("file:./Header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
<!-- Main Container -->
<main class="auth-wrapper">
    <div class="auth-card">

        <div class="brand-header">
            <div class="brand-logo">
                <img src="/favicon.ico" alt="Logo" class="brand-icon">
            </div>

        </div>
        <p class="auth-subtitle small mb-3"><?php echo t("登入");?>
<a class="text-link" href="/"><?php echo $_smarty_tpl->tpl_vars['config']->value['shop_name'];?>
</a><?php echo t("，获取更多折扣！");?>
</p>

        <form method="post" class="needs-validation">
            <div class="form-floating mb-4">
                <input type="text" class="form-control" name="username" placeholder="<?php echo t('用户名/手机号/邮箱');?>
"
                       required>
                <label for="username"><?php echo t("用户名/手机号/邮箱");?>
</label>
            </div>

            <div class="form-floating mb-4">
                <input type="password" class="form-control" name="password" placeholder="••••••••"
                       minlength="6" required>
                <label for="password"><?php echo t("密码");?>
</label>
            </div>

            <?php if ($_smarty_tpl->tpl_vars['config']->value['login_verification'] == 1) {?>
                <div class="row mb-4">
                    <div class="col-sm-6 col-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="image-captcha" name="captcha"
                                   placeholder="<?php echo t('请输入验证码');?>
">
                            <label class="form-label" for="image-captcha"><?php echo t("图形验证码");?>
</label>
                        </div>
                    </div>
                    <div class="col-sm-6 col-6 d-flex align-items-center">
                        <img src="/user/captcha/image?action=login"
                             data-acg-refresh="/user/captcha/image?action=login"
                             class="image-code" alt="<?php echo t('更换验证码');?>
">
                    </div>
                </div>
            <?php }?>

            <div class="d-flex justify-content-between align-items-center mb-3 mt-3">
                <div class="form-check">
                    <input id="rememberMe" name="remember" type="checkbox" value="1">
                    <label for="rememberMe"><?php echo t("保持会话");?>
</label>
                </div>
                <a href="<?php if ($_smarty_tpl->tpl_vars['config']->value['forget_type'] == 0) {?>/user/authentication/emailForget<?php } else { ?>/user/authentication/phoneForget<?php }?>" class="text-link small"><?php echo t("忘记密码？");?>
</a>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-gradient btn-lg"><?php echo t("登录");?>
</button>
            </div>
        </form>


        <?php if (getHookNum(\App\Consts\Hook::USER_VIEW_AUTH_LOGIN_BUTTON) > 0) {?>
            <!--begin::Separator-->
            <div class="divider small my-3">OR</div>
            <!--end::Separator-->
        <?php }?>

        <!--start::HOOK-->
        <?php echo hook(\App\Consts\Hook::USER_VIEW_AUTH_LOGIN_BUTTON);?>

        <!--end::HOOK-->

        <?php if ($_smarty_tpl->tpl_vars['config']->value['registered_state'] == 1) {?>
        <p class="text-center small mt-3 mb-0">
            <?php echo t("还没有账号？");?>

            <a class="text-link" href="/user/authentication/register"><?php echo t("立即注册");?>
</a>
        </p>
        <?php }?>
    </div>
</main>
<!-- END Main Container -->
<?php echo ready("/assets/user/controller/auth/login.js");?>

<?php $_smarty_tpl->_subTemplateRender("file:./Footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
}
}
