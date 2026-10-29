<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\TermsGet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

beforeEach(function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Blueprint::find('collections.blog.article')
        ->ensureField('author', ['type' => 'users', 'max_items' => 1])
        ->ensureField('contacts', ['type' => 'grid', 'fields' => [['handle' => 'person', 'field' => ['type' => 'users', 'max_items' => 1]]]])
        ->save();

    $this->editor = tap(Fixtures::makeSuper()->set('name', 'Other Editor'))->save();

    $this->post = tap(Entry::make()->collection('blog')->locale('en')->slug('post')->data([
        'title' => 'Post',
        'author' => [$this->editor->id()],
        'contacts' => [['id' => 'row00001', 'person' => $this->editor->id()]],
        'updated_by' => $this->editor->id(),
    ]))->save()->id();
});

it('shows other users by id and name only in augmented entries_get, as the Control Panel does without view users', function () {
    $stub = '{"id":"'.$this->editor->id().'","name":"Other Editor","api_url":null}';

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $this->post, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee($this->editor->email())
        ->assertDontSee('"super":true')
        ->assertSee('"author":'.$stub)
        ->assertSee('"person":'.$stub)
        ->assertSee('"updated_by":'.$stub);
});

it('shows the last editor by id and name only in augmented terms_get', function () {
    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP', 'updated_by' => $this->editor->id()])->save();

    Server::actingAs(Fixtures::makeUser('view tags terms'))
        ->tool(TermsGet::class, ['id' => 'tags::php', 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee($this->editor->email())
        ->assertDontSee('"super":true')
        ->assertSee('"updated_by":{"id":"'.$this->editor->id().'","name":"Other Editor","api_url":null}');
});

it('keeps other users\' email for a caller who can view users, as the Control Panel does', function () {
    $stub = '{"id":"'.$this->editor->id().'","name":"Other Editor","email":"'.$this->editor->email().'","api_url":null}';

    Server::actingAs(Fixtures::makeUser('view blog entries', 'view users'))
        ->tool(EntriesGet::class, ['id' => $this->post, 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('"super":true')
        ->assertSee('"author":'.$stub)
        ->assertSee('"updated_by":'.$stub);
});
