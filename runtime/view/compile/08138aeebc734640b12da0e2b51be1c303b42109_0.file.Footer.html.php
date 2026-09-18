<?php
/* Smarty version 3.1.46, created on 2026-09-18 18:34:52
  from '/workspace/app/View/User/Theme/Cartoon/Index/Footer.html' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '3.1.46',
  'unifunc' => 'content_6aad13cc9a3193_54601337',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '08138aeebc734640b12da0e2b51be1c303b42109' => 
    array (
      0 => '/workspace/app/View/User/Theme/Cartoon/Index/Footer.html',
      1 => 1789726831,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6aad13cc9a3193_54601337 (Smarty_Internal_Template $_smarty_tpl) {
?></div>
<?php if ($_smarty_tpl->tpl_vars['setting']->value['icp']) {?>
<footer>
    <?php echo $_smarty_tpl->tpl_vars['setting']->value['icp'];?>

</footer>
<?php }?>

<?php echo js(array("/assets/common/js/_.js","/assets/user/js/_index.js"),array("/assets/common/js/util/dict.js","/assets/common/js/jquery.min.js","/assets/common/js/toastr.min.js","/assets/common/js/component/loading.js","/assets/common/js/util.js","/assets/common/js/layer/layer.js","/assets/common/js/jquery.pjax.min.js","/assets/common/js/jquery.qrcode.min.js","/assets/common/js/format.js","/assets/common/js/message.js","/assets/common/js/component.js","/assets/common/js/layui/layui.js","/assets/common/js/jquery.treegrid.min.js","/assets/common/js/bootstrap/bootstrap.bundle.min.js","/assets/common/js/table/bootstrap-table.min.js","/assets/common/js/table/bootstrap-table-treegrid.min.js","/assets/common/js/component/form.js","/assets/common/js/component/search.js","/assets/common/js/component/xm-select.js","/assets/common/js/component/tree.select.js","/assets/common/js/component/authtree.js","/assets/common/js/component/table.js","/assets/common/js/component/select2.min.js","/assets/common/js/cache.js","/assets/common/js/editor/editor.js","/assets/common/js/editor/code/code.js","/assets/common/js/component/decimal.js","/assets/user/js/trade.js","/assets/user/js/treasure.js"));?>

<!--start::HOOK-->
<?php echo hook(\App\Consts\Hook::USER_GLOBAL_VIEW_BODY);?>

<?php echo hook(\App\Consts\Hook::USER_VIEW_INDEX_BODY);?>

<!--end::HOOK-->
</body>
<!--start::HOOK-->
<?php echo hook(\App\Consts\Hook::USER_GLOBAL_VIEW_FOOTER);?>

<?php echo hook(\App\Consts\Hook::USER_VIEW_INDEX_FOOTER);?>

<!--end::HOOK-->
</html><?php }
}
