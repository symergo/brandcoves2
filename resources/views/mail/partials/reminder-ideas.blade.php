{{--
  Three ideas for the person a reminder is about, and the way out of reminder
  emails. Included by mail/occasion-reminder and, for an edited template, by
  mail/templated, so rewording a reminder never loses either.

  Emitted as HTML, not Markdown, for the reason cove-digest gives: a product
  title with a stray * or | in it must not be read as markup. No blank line may
  fall inside a block, so the rows sit on consecutive lines.

  Catalogue products only, which never includes Amazon (invariant 6), and
  nothing about any list: not what is on it, not what was claimed.
--}}
@if (! empty($ideas))
<p><strong>{{ __('site.reminders.ideas_heading', ['name' => $name]) }}</strong></p>
@foreach ($ideas as $idea)
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px 0;"><tr>
<td width="80" valign="top">@if ($idea['image'])<a href="{{ $idea['url'] }}"><img src="{{ $idea['image'] }}" width="72" alt="" style="max-width: 72px; height: auto;"></a>@endif</td>
<td valign="top"><a href="{{ $idea['url'] }}">{{ $idea['title'] }}</a>@if ($idea['price'])<br>{{ __('site.reminders.idea_from', ['price' => $idea['price']]) }}@endif<br><a href="{{ $idea['addUrl'] }}">{{ __('site.reminders.idea_add', ['name' => $name]) }}</a></td>
</tr></table>
@endforeach
@if (! empty($ideasUrl))
<x-mail::button :url="$ideasUrl">
{{ __('site.reminders.ideas_finder') }}
</x-mail::button>
@endif
<p>{{ __('site.reminders.ideas_why') }}</p>
@endif
