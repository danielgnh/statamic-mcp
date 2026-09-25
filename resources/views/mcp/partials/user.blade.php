{{--
    The user cell of Statamic's Users listing: avatar and email, linking to the
    user. The name reaches ui-avatar inside @js's JSON string, which Vue never
    evaluates as a template. A deleted user has no page, so the row keeps the ID.
--}}
@if ($user)
    <inertia-link href="{{ $user->editUrl() }}" class="title-index-field">
        <ui-avatar :user="@js(['id' => $user->id(), 'name' => $user->name(), 'avatar' => $user->avatar(), 'initials' => $user->initials()])" class="size-8 text-xs ltr:mr-2 rtl:ml-2"></ui-avatar>
        <span v-pre>{{ $user->email() }}</span>
    </inertia-link>
@else
    <span v-pre>{{ $userId }}</span>
@endif
