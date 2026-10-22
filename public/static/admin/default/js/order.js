FromInit('form.form-validation-pay');
FromInit('form.form-validation-take');
// 改价表单没有通用 form-validation class，需要显式初始化，否则会走浏览器原生提交。
FromInit('form.form-validation-modify-price');
$(function()
{
    // 支付操作
    var $form_pay = $('form.form-validation-pay');
    $(document).on('click', '.submit-pay', function()
    {
        var payment_id = parseInt($(this).data('payment-id') || 0);
        $form_pay.find('input[name=id]').val($(this).data('id'));
        $form_pay.find('input[name=payment_id]').val(0);
        $form_pay.find('ul.payment-list li').each(function(k, v)
        {
            var temp = parseInt($(this).data('value') || 0);
            if(temp == payment_id)
            {
                $(this).addClass('selected');
                $form_pay.find('input[name=payment_id]').val(payment_id);
            } else {
                $(this).removeClass('selected');
            }
        });
    });
    // 支付操作表单
    $form_pay.find('button[type=submit]').on('click', function()
    {
        var id = parseInt($form_pay.find('input[name=id]').val() || 0);
        if(id == 0)
        {
            Prompt(window['lang_order_id_empty'] || '订单id有误');
            return false;
        }
        var payment_id = parseInt($form_pay.find('input[name=payment_id]').val() || 0);
        if(payment_id == 0)
        {
            Prompt(window['lang_payment_choice_tips'] || '请选择支付方式');
            return false;
        }
    });

    // 待付款订单改价
    var $form_modify_price = $('form.form-validation-modify-price');
    $(document).on('click', '.submit-modify-price', function()
    {
        var order_id = parseInt($(this).attr('data-id') || 0, 10);
        var order_no = $(this).attr('data-order-no') || '-';
        var total_price = $(this).attr('data-total-price') || '';
        $form_modify_price.find('input[name=id]').val(order_id > 0 ? order_id : 0);
        $form_modify_price.find('.modify-price-order-no').text('订单号：' + order_no);
        $form_modify_price.find('input[name=new_total_price]').val(total_price);
        $form_modify_price.find('textarea[name=reason]').val('');
        $form_modify_price.find('.modify-price-current').text(total_price || '0.00');
    });
    $form_modify_price.find('button[type=submit]').on('click', function()
    {
        var id = parseInt($form_modify_price.find('input[name=id]').val() || 0);
        if(id == 0)
        {
            Prompt(window['lang_order_id_empty'] || '订单id有误');
            return false;
        }
        var new_price = $.trim($form_modify_price.find('input[name=new_total_price]').val() || '');
        if(!/^\d{1,8}(?:\.\d{1,2})?$/.test(new_price))
        {
            Prompt('请输入合法的新应付金额');
            return false;
        }
        if($.trim($form_modify_price.find('textarea[name=reason]').val() || '') === '')
        {
            Prompt('请填写改价原因');
            return false;
        }
    });

    // 取货操作
    var $form_take = $('form.form-validation-take');
    $(document).on('click', '.submit-take', function()
    {
        $form_take.find('input[name=id]').val($(this).data('id') || 0);
        $form_take.find('input[name=user_id]').val($(this).data('user-id') || 0);
    });
    // 取货操作表单
    $form_take.find('button[type=submit]').on('click', function()
    {
        if(parseInt($form_take.find('input[name=id]').val() || 0) == 0)
        {
            Prompt(window['lang_order_id_empty'] || '订单id有误');
            return false;
        }
    });

});
