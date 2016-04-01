<div class="container">
	<div class="row">
		<div class="col-md-12">
			<ul class="breadcrumb">
				<li><i class="glyphicon glyphicon-book"></i> <?php echo $this->lang->line('problems'); ?></li>
				<li><i class="glyphicon glyphicon-list-alt"></i> <?php echo $this->lang->line('problem'); ?> <?php echo $problem_id; ?></li>
				<li><i class="glyphicon glyphicon-plus"></i> <a href="<?php echo site_url('admin/problem/editTestcasePacket/' . $problem_id . '/0/' . $page_offset); ?>"><?php echo $this->lang->line('add_new_testcase_packet'); ?></a></li>
			</ul>
		</div>
	</div>

	<div class="row">
		<div class="col-md-12">
			<?php if ($this->session->flashdata('add_successful') != ''): ?>
				<div class="alert alert-success">
					<?php echo $this->lang->line('add_testcase_packet_successful'); ?>
				</div>
			<?php endif; ?>

			<?php if ($this->session->flashdata('delete_successful') != ''): ?>
				<div class="alert alert-success">
					<?php echo $this->lang->line('delete_testcase_packet_successful'); ?>
				</div>
			<?php endif; ?>
			<?php if ($this->session->flashdata('error') != ''): ?>
				<div class="alert alert-danger">
					<?php echo $this->session->flashdata('error'); ?>
				</div>
			<?php endif; ?>

			<h3><?php echo $this->lang->line('edit_problem_testcase_packets'); ?></h3>
			<table class="table table-bordered table-striped">
				<thead>
				<tr>
					<th class="id-th"><i class="glyphicon glyphicon-tag"></i></th>
					<th><i class="glyphicon glyphicon-eye-open"></i> <?php echo $this->lang->line('short_description'); ?></th>
					<th class="problem-limits-th"><i class="glyphicon glyphicon-file"></i> <?php echo $this->lang->line('testcases'); ?></th>
					<th class="operations-th"><i class="glyphicon glyphicon-cog"></i></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($testcase_packets as $v) : ?>
					<tr>
						<td class="id-td"><?php echo $v['packet_order_id']; ?></td>
						<td><?php echo $v['short_description']; ?></td>
						<td><?php echo $v['tc_count']; ?></td>
						<td class="operations-td">
							<a href="<?php echo site_url('admin/problem/editTestcasePacket/' . $problem_id . '/' . $v['id'] . '/' . $page_offset); ?>" rel="tooltip" title="<?php echo $this->lang->line('edit'); ?>"><i class="glyphicon glyphicon-pencil"></i></a>
							<a href="<?php echo site_url('admin/problem/editTestcases/' . $problem_id . '/' . $v['id'] . '/' . $page_offset); ?>" rel="tooltip" title="<?php echo $this->lang->line('testcase'); ?>"><i class="glyphicon glyphicon-file"></i></a>
							<a href="<?php echo site_url('admin/problem/deleteTestcasePacket/' . $problem_id . '/'. $v['id'] . '/' . $page_offset); ?>" rel="tooltip" title="<?php echo $this->lang->line('delete'); ?>"><i class="glyphicon glyphicon-trash"></i></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<hr />

			<a class="btn btn-default" href="<?php echo site_url('admin/problem/viewAll/' . $page_offset); ?>"><?php echo $this->lang->line('cancel'); ?></a>

		</div>
	</div>
</div>