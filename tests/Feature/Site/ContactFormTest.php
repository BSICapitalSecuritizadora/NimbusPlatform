<?php

use App\Mail\ContactFormMail;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('contact page loads', function () {
    $this->get(route('site.contact'))->assertStatus(200);
});

test('contact form saves to database and sends email', function () {
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

    Mail::assertSent(ContactFormMail::class, function (ContactFormMail $mail) {
        return $mail->data['name'] === 'João Silva'
            && $mail->data['subject'] === 'Comercial e novos negócios';
    });
});

test('contact form requires name email subject and message', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [])
        ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

    Mail::assertNotSent(ContactFormMail::class);
    $this->assertDatabaseCount('contact_messages', 0);
});

test('contact form rejects invalid email', function () {
    Mail::fake();

    $this->post(route('site.contact.submit'), [
        'name' => 'João Silva',
        'email' => 'not-an-email',
        'subject' => 'Assuntos institucionais',
        'message' => 'Teste.',
    ])->assertSessionHasErrors(['email']);

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
});
