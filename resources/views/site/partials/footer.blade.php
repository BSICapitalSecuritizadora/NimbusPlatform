<footer class="footer py-5 mt-5">
    <div class="container">
        <div class="row gy-4 align-items-center">
            <div class="col-md-4 text-center text-md-start">
                <a class="navbar-brand fw-bold mb-3 d-inline-block" href="{{ route('site.home') }}">
                    <img src="{{ asset('images/logo-mob.png') }}" alt="BSI Capital" style="max-height: 48px;">
                </a>
                <p class="section-copy small mb-3">
                    Securitizadora registrada na CVM. Estruturamos e gerimos operações de crédito (CRI, CRA e CR) com rigor técnico, controle documental e reporte contínuo aos investidores.
                </p>
                <p class="small mb-3" style="color: var(--muted); line-height: 1.65;">
                    CNPJ 11.257.352/0001-43<br>
                    Av. das Nações Unidas, 14.401, Sala 712 e 713<br>
                    Chácara Santo Antônio, São Paulo – SP
                </p>
                <div class="d-flex gap-3 justify-content-center justify-content-md-start mt-2">
                    <a href="https://br.linkedin.com/company/bsi-capital-securitizadora-s-a" target="_blank" class="text-muted text-decoration-none" title="LinkedIn">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                    </a>
                    <a href="https://www.instagram.com/bsicapitalsec/" target="_blank" class="text-muted text-decoration-none" title="Instagram">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                    </a>
                    <a href="https://www.youtube.com/@BSICapitalSecuritizadora" target="_blank" class="text-muted text-decoration-none" title="YouTube">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                    </a>
                </div>
            </div>

            <div class="col-md-4">
                <div class="row g-4">
                    <div class="col-6 col-sm-6">
                        <div class="footer-heading">Atalhos</div>
                        <div class="d-flex flex-column gap-2 fw-medium">
                            <a href="{{ route('site.emissions') }}" class="footer-link">Ver Emissões</a>
                            <a href="{{ route('site.ri') }}" class="footer-link">Rel. com Investidores</a>
                            <a href="{{ route('site.intelligence') }}" class="footer-link">BSI Intelligence</a>
                            <a href="{{ route('proposal.create') }}" class="footer-link">Envie sua proposta</a>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6">
                        <div class="footer-heading">Conformidade</div>
                        <div class="d-flex flex-column gap-2 fw-medium">
                            <a href="{{ route('site.compliance') }}#canal-de-etica" class="footer-link">Canal de Ética</a>
                            <a href="{{ route('site.compliance') }}" class="footer-link">Compliance</a>
                            <a href="{{ route('site.governance') }}" class="footer-link">Governança</a>
                            <a href="{{ route('site.contact', ['assunto' => 'ouvidoria']) }}" class="footer-link">Ouvidoria</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 text-center text-md-end">
                <div class="footer-seal">
                    <div class="footer-seal-label">Autorregulação</div>
                    <img src="{{ asset('images/selo-anbima.jpg') }}" class="img-fluid" alt="Selo ANBIMA Securitizadora" style="max-height: 84px; border-radius: 6px;">
                </div>
            </div>
        </div>

        <hr class="my-4 border-brand-subtle opacity-100">

        <div class="text-center small pb-2">
            <div class="text-muted mb-1">
                © {{ date('Y') }} BSI Capital Securitizadora S.A. Todos os direitos reservados.
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-2 align-items-center">
                <a href="{{ route('site.privacy-policy') }}" class="footer-legal-link">Política de Privacidade</a>
                <span class="text-muted opacity-50">•</span>
                <a href="{{ route('site.terms-of-use') }}" class="footer-legal-link">Termos de Uso</a>
                <span class="text-muted opacity-50">•</span>
                <a href="{{ route('site.compliance') }}#canal-de-etica" class="footer-legal-link" style="color: var(--gold);">Canal de Ética</a>
            </div>
        </div>
    </div>
</footer>
