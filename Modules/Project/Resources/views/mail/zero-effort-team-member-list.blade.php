<div>
	<style>
		.line {
			line-height: 1px;
		}
	</style>
     @foreach ($projectDetail as $project)
		
	 @endforeach
	<p>Hello {{ $project['name'] }},</p>
	<p>We found some projects where the expected hours are zero for you or team members where you are assigned as key account manager. Please update these projects:</p>
	<table class="table">
		<thead>
			<tr>
			<th scope="col">Project Name</th>
			</tr>
		</thead>
		<tbody>
				<tr>
					<td>
						@foreach ($projectDetail as $project)
						
						    <li><a href="{{ route('project.show', $project['project']) }}">{{ $project['project']->name }}</a></li>
						@endforeach
					</td>
				</tr>
        </tbody>
	</table>
	<br>
	<p class="line">Thanks,</p>
	<p class="line">Portal Team</p>
	<p class="line">ColoredCow</p>
</div>
