<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>LEADSWHATS API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
  <style>
    body { margin: 0; background: #fafafa; }
    
    .auth-status {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: #2d2d2d;
      color: #fff;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      z-index: 9999;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      opacity: 0.8;
      transition: all 0.3s ease;
      cursor: pointer;
    }
    .auth-status:hover {
      opacity: 1;
    }
    .auth-status.active {
      background: #1a7f37;
      opacity: 1;
    }
    .auth-status .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }
    .auth-status .dot.red { background: #ff4444; }
    .auth-status .dot.green { background: #44ff44; }
    .auth-status .token-info {
      font-size: 10px;
      opacity: 0.7;
      max-width: 120px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-family: monospace;
    }
    
    .loading {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      font-size: 16px;
      color: #666;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    .loading .spinner {
      width: 40px;
      height: 40px;
      border: 4px solid #e0e0e0;
      border-top: 4px solid #1a7f37;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .loading.error { color: #ff4444; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  
  <div class="loading" id="loading">
    <div class="spinner"></div>
    <span>Carregando documentação...</span>
  </div>
  
  <div id="auth-status" class="auth-status">
    <span class="dot red" id="auth-dot"></span>
    <span id="auth-text">Não autenticado</span>
    <span class="token-info" id="token-preview"></span>
  </div>

  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
  
  <script>
    (function() {
      console.log('🚀 Inicializando Swagger UI...');
      
      const STORAGE_KEY = 'swagger_bearer_token';
      const AUTHORIZED_KEY = 'authorized';
      const AUTH_KEY = 'BearerAuth';
      
      const authDot = document.getElementById('auth-dot');
      const authText = document.getElementById('auth-text');
      const tokenPreview = document.getElementById('token-preview');
      const authStatus = document.getElementById('auth-status');
      const loading = document.getElementById('loading');
      
      let isCleaning = false;
      
      function updateAuthStatus(token) {
        if (token && token.length > 0) {
          authDot.className = 'dot green';
          authText.textContent = 'Autenticado';
          tokenPreview.textContent = token.substring(0, 15) + '...';
          authStatus.classList.add('active');
        } else {
          authDot.className = 'dot red';
          authText.textContent = 'Não autenticado';
          tokenPreview.textContent = '';
          authStatus.classList.remove('active');
        }
      }
      
      // 🔥 FUNÇÃO PARA LIMPAR COMPLETAMENTE (SÓ NO LOGOUT)
      function clearAllAuth() {
        if (isCleaning) return;
        isCleaning = true;
        
        console.log('🗑️ Limpando autenticação (logout)...');
        
        // Remover do localStorage
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(AUTHORIZED_KEY);
        sessionStorage.clear();
        
        // Atualizar UI
        updateAuthStatus(null);
        
        // Limpar estado do Swagger
        if (window.ui) {
          try {
            window.ui.authActions.logout();
          } catch (e) {}
          
          try {
            window.ui.authActions.authorize({
              [AUTH_KEY]: {
                name: AUTH_KEY,
                schema: {
                  type: 'http',
                  scheme: 'bearer',
                  bearerFormat: 'Token'
                },
                value: ''
              }
            });
          } catch (e) {}
        }
        
        console.log('✅ Limpeza completa!');
        
        setTimeout(() => {
          isCleaning = false;
          // 🔥 RECARREGAR PARA MOSTRAR O BOTÃO AUTHORIZE
          window.location.reload();
        }, 300);
      }
      
      // 🔥 NÃO LIMPAR NA INICIALIZAÇÃO - APENAS VERIFICAR O ESTADO
      let savedToken = null;
      try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          const data = JSON.parse(stored);
          if (data && data.token) {
            savedToken = data.token;
            console.log('🔑 Token encontrado:', savedToken.substring(0, 20) + '...');
            updateAuthStatus(savedToken);
          }
        } else {
          console.log('ℹ️ Nenhum token encontrado');
          // Se não tem token, garantir que "authorized" também seja removido
          if (localStorage.getItem(AUTHORIZED_KEY) !== null) {
            console.log('⚠️ "authorized" sem token, removendo...');
            localStorage.removeItem(AUTHORIZED_KEY);
          }
        }
      } catch (e) {
        console.warn('⚠️ Erro ao ler token:', e);
      }
      
      // 🔥 CONFIGURAÇÃO DO SWAGGER UI
      const ui = SwaggerUIBundle({
        url: '/api/openapi.json',
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [
          SwaggerUIBundle.presets.apis,
          SwaggerUIStandalonePreset
        ],
        layout: 'BaseLayout',
        persistAuthorization: true,
        docExpansion: 'list',
        defaultModelsExpandDepth: 1,
        defaultModelExpandDepth: 1,
        onComplete: function() {
          console.log('✅ Swagger UI carregado');
          loading.style.display = 'none';
          
          setTimeout(() => {
            const authBtn = document.querySelector('.swagger-ui .auth-wrapper .authorize');
            if (authBtn) {
              console.log('✅ Botão Authorize encontrado!');
            } else {
              console.warn('⚠️ Botão Authorize não encontrado');
              // Se não tem token e não tem botão, pode ser que o "authorized" esteja travado
              if (!localStorage.getItem(STORAGE_KEY)) {
                const authorized = localStorage.getItem(AUTHORIZED_KEY);
                if (authorized !== null) {
                  console.log('⚠️ "authorized" travado sem token, removendo...');
                  localStorage.removeItem(AUTHORIZED_KEY);
                  setTimeout(() => window.location.reload(), 500);
                }
              }
            }
          }, 500);
          
          if (savedToken && window.ui) {
            console.log('🔄 Aplicando token salvo...');
            try {
              window.ui.authActions.authorize({
                [AUTH_KEY]: {
                  name: AUTH_KEY,
                  schema: {
                    type: 'http',
                    scheme: 'bearer',
                    bearerFormat: 'Token'
                  },
                  value: savedToken
                }
              });
              console.log('✅ Token aplicado com sucesso!');
            } catch (e) {
              console.error('❌ Erro ao aplicar token:', e);
            }
          }
        },
        onFailure: function(error) {
          console.error('❌ Erro ao carregar Swagger:', error);
          loading.innerHTML = '❌ Erro ao carregar documentação';
          loading.classList.add('error');
        }
      });
      
      // 🔥 INTERCEPTAR AUTORIZAÇÃO
      const originalAuthorize = ui.authActions.authorize;
      ui.authActions.authorize = function(authData) {
        console.log('🔐 Autorização interceptada:', authData);
        
        if (authData && authData[AUTH_KEY]) {
          const token = authData[AUTH_KEY].value;
          
          if (!token || token.length === 0) {
            console.log('🚪 Logout detectado');
            clearAllAuth();
          } else {
            try {
              localStorage.setItem(STORAGE_KEY, JSON.stringify({
                token: token,
                timestamp: new Date().toISOString()
              }));
              console.log('✅ Token salvo');
              updateAuthStatus(token);
            } catch (e) {
              console.error('❌ Erro ao salvar token:', e);
            }
          }
        }
        
        return originalAuthorize.call(this, authData);
      };
      
      // 🔥 INTERCEPTAR LOGOUT
      const originalLogout = ui.authActions.logout;
      ui.authActions.logout = function() {
        console.log('🚪 Logout interceptado');
        clearAllAuth();
        return originalLogout.call(this);
      };
      
      // 🔥 MONITORAR CLIQUE NO LOGOUT
      document.addEventListener('click', function(e) {
        const target = e.target;
        if (target && target.textContent && target.textContent.trim() === 'Logout') {
          console.log('🖱️ Logout clicado');
          setTimeout(() => {
            const stored = localStorage.getItem(STORAGE_KEY);
            const authorized = localStorage.getItem(AUTHORIZED_KEY);
            
            if (!stored && authorized !== null) {
              console.log('⚠️ "authorized" sem token, removendo...');
              localStorage.removeItem(AUTHORIZED_KEY);
              updateAuthStatus(null);
              setTimeout(() => window.location.reload(), 300);
            }
            
            if (!stored) {
              updateAuthStatus(null);
            }
          }, 300);
        }
      });
      
      window.ui = ui;
      
      console.log('✅ Swagger UI pronto!');
      console.log('📋 O botão "Authorize" deve aparecer no topo da página');
      console.log('🔑 Token será mantido entre recarregamentos');
      console.log('🚪 Apenas o logout remove o token');
      
      window.debugAuth = {
        getToken: () => localStorage.getItem(STORAGE_KEY),
        getAuthorized: () => localStorage.getItem(AUTHORIZED_KEY),
        clearToken: clearAllAuth,
        status: () => authStatus.classList.contains('active') ? 'Autenticado' : 'Não autenticado',
        showAll: () => {
          console.log('📋 Todos os itens do localStorage:');
          for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            console.log(`  ${key}: ${localStorage.getItem(key)}`);
          }
        }
      };
      
      console.log('🔧 Comandos de debug: window.debugAuth');
    })();
  </script>
</body>
</html>