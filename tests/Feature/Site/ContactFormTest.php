<?php

use App\Mail\ContactFormMail;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('contact page loads', function () {
    $this->get(route('site.contact'))
        ->assertSuccessful()
        ->assertSee('name="website"', false);
});

test('contact form saves to database and queues email', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [
        'name' => 'João Silva',
        'email' => 'joao@empresa.com.br',
        'phone' => '+55 11 99999-9999',
        'subject' => 'Comercial e novos negócios',
        'message' => 'Gostaria de saber mais sobre a estruturação de CRI.',
    ])->assertRedirect()->assertSessionHas('contact_success');

    $this->assertDatabaseHas('contact_messages', [
        'name' => 'João Silva',
        'email' => 'joao@empresa.com.br',
        'subject' => 'Comercial e novos negócios',
        'status' => ContactMessage::STATUS_NEW,
    ]);

    Mail::assertQueued(ContactFormMail::class, function (ContactFormMail $mail) {
        return $mail->data['name'] === 'João Silva'
            && $mail->data['subject'] === 'Comercial e novos negócios';
    });
    Mail::assertNotSent(ContactFormMail::class);
});

test('contact form requires name email subject and message', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [])
        ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

    Mail::assertNothingOutgoing();
    $this->assertDatabaseCount('contact_messages', 0);
});

test('contact form rejects invalid email', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [
        'name' => 'João Silva',
        'email' => 'not-an-email',
        'subject' => 'Comercial e novos negócios',
        'message' => 'Teste.',
    ])->assertSessionHasErrors(['email']);

    $this->assertDatabaseCount('contact_messages', 0);
});

test('contact form rejects an invalid subject', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [
        'name' => 'João Silva',
        'email' => 'joao@empresa.com.br',
        'subject' => 'Assunto injetado',
        'message' => 'Teste.',
    ])->assertInvalid(['subject']);

    Mail::assertNothingOutgoing();
    $this->assertDatabaseCount('contact_messages', 0);
});

test('contact form rejects a filled honeypot', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [
        'name' => 'Robô',
        'email' => 'bot@example.com',
        'subject' => 'Comercial e novos negócios',
        'message' => 'Spam.',
        'website' => 'https://spam.example.com',
    ])->assertInvalid(['website']);

    Mail::assertNothingOutgoing();
    $this->assertDatabaseCount('contact_messages', 0);
});

test('contact form phone is optional', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [
        'name' => 'Maria Costa',
        'email' => 'maria@empresa.com.br',
        'subject' => 'Relações com investidores',
        'message' => 'Solicitação de documentação.',
    ])->assertRedirect()->assertSessionHas('contact_success');

    $this->assertDatabaseHas('contact_messages', [
        'email' => 'maria@empresa.com.br',
        'phone' => null,
        'status' => ContactMessage::STATUS_NEW,
    ]);

    Mail::assertQueued(ContactFormMail::class);
});

test('contact form is limited to five submissions per hour by IP', function () {
    Mail::fake();

    $payload = [
        'name' => 'João Silva',
        'email' => 'joao@empresa.com.br',
        'subject' => 'Comercial e novos negócios',
        'message' => 'Gostaria de saber mais sobre a estruturação de CRI.',
    ];

    foreach (range(1, 5) as $submission) {
        $this->post(route('site.contact.submit'), $payload)
            ->assertRedirect()
            ->assertSessionHas('contact_success');
    }

    $this->post(route('site.contact.submit'), $payload)
        ->assertTooManyRequests();

    $this->assertDatabaseCount('contact_messages', 5);
    Mail::assertQueued(ContactFormMail::class, 5);
    Mail::assertNotSent(ContactFormMail::class);
});
