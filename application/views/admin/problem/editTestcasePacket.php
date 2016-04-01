<script type="text/javascript">
	tinyMCE.init({
		selector: "textarea",
		plugins: [
			"code table link image preview textcolor"
		],
		toolbar1: "styleselect | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent",
		toolbar2: "table link image | code preview",
		image_advtab: true,
		width: "768",
		height: "600",
		menubar: false,
		relative_urls: false,
		remove_script_host: false
	});
</script>


<div class="container">
	<div class="row">
		<div class="col-md-12">
			<ul class="breadcrumb">
				<li><i class="glyphicon glyphicon-book"></i> <?php echo $this->lang->line('problems'); ?></li>
				<li><i class="glyphicon glyphicon-list-alt"></i> <?php echo $this->lang->line('problem'); ?> <?php echo $problem_id; ?></li>
				<li><i class="glyphicon glyphicon-list-alt"></i> <?php echo isset($testcase_packet) ? $this->lang->line('testcase_packet') . ' ' . $testcase_packet['id'] : $this->lang->line('new_testcase_packet'); ?></li>
			</ul>
		</div>
	</div>

	<div class="row">

		<div class="col-md-12">
			<h3><?php echo isset($testcase_packet) ? $this->lang->line('edit_testcase_packet') : $this->lang->line('add_testcase_packet'); ?></h3>
			<form class="form-horizontal" action="" method="post">
				<div class="form-group<?php echo form_error('form[packet_order_id]') == '' ? '' : ' has-error'; ?>">
					<label class="col-sm-2 control-label"><?php echo $this->lang->line('packet_order_id'); ?></label>
					<div class="col-sm-4">
						<input name="form[packet_order_id]" type="text" class="form-control" maxlength="50" value="<?php echo set_value('form[packet_order_id]', @$testcase_packet['packet_order_id']); ?>"/>
						<span class="help-block"><?php echo form_error('form[packet_order_id]'); ?></span>
					</div>
				</div>

				<div class="form-group<?php echo form_error('form[score]') == '' ? '' : ' has-error'; ?>">
					<label class="col-sm-2 control-label"><?php echo $this->lang->line('score'); ?></label>
					<div class="col-sm-4">
						<input name="form[score]" type="text" class="form-control" maxlength="50" value="<?php echo set_value('form[score]', @$testcase_packet['score']); ?>"/>
						<span class="help-block"><?php echo form_error('form[score]'); ?></span>
					</div>
				</div>

				<div class="form-group<?php echo form_error('form[short_description]') == '' ? '' : ' has-error'; ?>">
					<label class="col-sm-2 control-label"><?php echo $this->lang->line('short_description'); ?></label>
					<div class= "col-sm-8">
						<textarea  name="form[short_description]"><?php echo set_value('form[short_description]', isset($testcase_packet) ? @$testcase_packet['short_description'] : $this->lang->line('default_short_description')); ?></textarea>
						<span class="help-block"><?php echo form_error('form[short_description]'); ?></span>
						<div>
						</div>

						<div class="form-actions">
							<button type="submit" class="btn btn-danger"><?php echo isset($testcase_packet) ? '<i class="glyphicon glyphicon-download-alt"></i> ' . $this->lang->line('save') : '<i class="glyphicon glyphicon-plus"></i> ' . $this->lang->line('add'); ?></button>
							<a class="btn btn-default" href="<?php echo site_url('admin/problem/viewAll/' . $page_offset); ?>"><?php echo $this->lang->line('cancel'); ?></a>
						</div>
			</form>
		</div>
	</div>
</div>