@props(['url'])
<tr>
<td class="header">
<!-- <div style="text-align: center; padding: 20px 0;">
<img src="{!! asset('logo.svg') !!}" alt="{{ config('app.name') }}" style="height: 50px;" />
</div> -->
<a href="{{ $url }}" style="display: inline-block;">
<img src="{!! asset('logo.svg') !!}" class="logo" alt="Laravel Logo">
</a>
</td>
</tr>
