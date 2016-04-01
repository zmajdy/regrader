<div class="container">
	<div class="row">
		<div class="col-md-12">
			<table class="table table-bordered table-condensed table-scoreboard">
				<thead>
					<tr>
						<th class="id-th">#</th>
						<?php if ($show_institution_logo): ?>
							<th class="scoreboard-institution-logo-th"></th>
						<?php endif; ?>
						<th class="scoreboard-name-th"><?php echo $this->lang->line('name'); ?></th>
						<th class="scoreboard-score-th"><?php echo $this->lang->line('score'); ?></th>
						<?php foreach ($problems as $v) : ?>
							<th class="scoreboard-problem-th"><?php echo $v['alias']; ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>

				<tbody>
					<?php
						$rank = 0;
						$temp = 1;

						$prev_total_score = -1;
						$prev_last_submit_time = -1;
						foreach ($scores as $v) :
							if ($v['total_score'] != $prev_total_score or $v['last_submit_time'] != $prev_last_submit_time)
							{
								$rank += $temp;
								$temp = 1;
							}
							else
								$temp++;

							$prev_total_score = $v['total_score'];
							$prev_last_submit_time = $v['last_submit_time'];

							?>
							<tr>
							<td class="id-td"><?php echo $rank; ?></td>
							<?php if ($show_institution_logo): ?>
								<td>
									<div class="scoreboard-institution-logo">
										<img src="<?php echo isset($raw) ? '' : base_url(); ?>files/<?php echo $v['institution']; ?>.jpg" />
									</div>
								</td>
							<?php endif; ?>
							<td>
								<div class="scoreboard-name">
									<?php echo $v['name']; ?>
								</div>
								<div class="scoreboard-institution">
									<?php echo $v['institution']; ?>
								</div>
							</td>

							<td class="scoreboard-total-score-td" style="background-color: <?php echo $v['total_color']; ?>;"><?php echo $v['total_score']; ?></td>

							<?php
							foreach ($v['score'] as $w) :
								?>

								<td class="scoreboard-problem-td" style="background-color: <?php echo $w['color']; ?>;"><?php echo $w['score']; ?></td>
								
							<?php endforeach; ?>
							</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>