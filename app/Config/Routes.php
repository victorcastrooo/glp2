<?php
/**
 * Routes.php - Sistema de roteamento da aplicação
 * 
 * Este arquivo contém o sistema de roteamento que faz o mapeamento entre URLs e controladores,
 * permitindo a definição e gerenciamento das rotas da aplicação.
 */

// Impedir acesso direto ao arquivo
if (!defined('BASE_PATH')) {
    exit('Acesso direto não permitido');
}

/**
 * -------------------------------------------------------------------------
 * CLASSE DE ROTEAMENTO
 * -------------------------------------------------------------------------
 */
class Router {
    /**
     * @var array Armazena todas as rotas registradas
     */
    private $routes = [];
    
    /**
     * @var string Rota padrão quando nenhuma outra corresponder
     */
    private $defaultRoute;
    
    /**
     * @var callable Função a ser chamada quando nenhuma rota corresponder
     */
    private $notFoundCallback;
    
    /**
     * @var array Armazena parâmetros identificados nas URLs
     */
    private $params = [];
    
    /**
     * @var array Middlewares globais que serão aplicados a todas as rotas
     */
    private $globalMiddlewares = [];
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->defaultRoute = [
            'controller' => 'HomeController',
            'method' => 'index'
        ];
    }
    
    /**
     * Adiciona uma rota ao sistema
     * 
     * @param string $method Método HTTP (GET, POST, etc)
     * @param string $pattern Padrão da URL
     * @param mixed $callback Controlador@método ou função anônima
     * @param array $middlewares Middlewares específicos para esta rota
     * @return Router Retorna a instância para encadeamento
     */
    public function add($method, $pattern, $callback, $middlewares = []) {
        $method = strtoupper($method);
        
        // Formatar pattern para usar com regex
        $pattern = $this->formatPattern($pattern);
        
        // Adicionar rota ao array
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'callback' => $callback,
            'middlewares' => $middlewares
        ];
        
        return $this;
    }
    
    /**
     * Formata o padrão da URL para uso com expressões regulares
     * 
     * @param string $pattern Padrão original
     * @return string Padrão formatado
     */
    private function formatPattern($pattern) {
        // Remover barras iniciais e finais
        $pattern = trim($pattern, '/');
        
        // Se for vazio, considerar home (/)
        if (empty($pattern)) {
            $pattern = '';
        }
        
        return $pattern;
    }
    
    /**
     * Adiciona uma rota para requisições GET
     * 
     * @param string $pattern Padrão da URL
     * @param mixed $callback Controlador@método ou função anônima
     * @param array $middlewares Middlewares específicos para esta rota
     * @return Router
     */
    public function get($pattern, $callback, $middlewares = []) {
        return $this->add('GET', $pattern, $callback, $middlewares);
    }
    
    /**
     * Adiciona uma rota para requisições POST
     * 
     * @param string $pattern Padrão da URL
     * @param mixed $callback Controlador@método ou função anônima
     * @param array $middlewares Middlewares específicos para esta rota
     * @return Router
     */
    public function post($pattern, $callback, $middlewares = []) {
        return $this->add('POST', $pattern, $callback, $middlewares);
    }
    
    /**
     * Adiciona uma rota para requisições PUT
     * 
     * @param string $pattern Padrão da URL
     * @param mixed $callback Controlador@método ou função anônima
     * @param array $middlewares Middlewares específicos para esta rota
     * @return Router
     */
    public function put($pattern, $callback, $middlewares = []) {
        return $this->add('PUT', $pattern, $callback, $middlewares);
    }
    
    /**
     * Adiciona uma rota para requisições DELETE
     * 
     * @param string $pattern Padrão da URL
     * @param mixed $callback Controlador@método ou função anônima
     * @param array $middlewares Middlewares específicos para esta rota
     * @return Router
     */
    public function delete($pattern, $callback, $middlewares = []) {
        return $this->add('DELETE', $pattern, $callback, $middlewares);
    }
    
    /**
     * Adiciona uma rota para requisições PATCH
     * 
     * @param string $pattern Padrão da URL
     * @param mixed $callback Controlador@método ou função anônima
     * @param array $middlewares Middlewares específicos para esta rota
     * @return Router
     */
    public function patch($pattern, $callback, $middlewares = []) {
        return $this->add('PATCH', $pattern, $callback, $middlewares);
    }
    
    /**
     * Adiciona uma rota para qualquer método HTTP
     * 
     * @param string $pattern Padrão da URL
     * @param mixed $callback Controlador@método ou função anônima
     * @param array $middlewares Middlewares específicos para esta rota
     * @return Router
     */
    public function any($pattern, $callback, $middlewares = []) {
        return $this->add('ANY', $pattern, $callback, $middlewares);
    }
    
    /**
     * Configura um grupo de rotas com prefixo comum
     * 
     * @param string $prefix Prefixo comum das rotas
     * @param callable $callback Função para definir as rotas do grupo
     * @param array $middlewares Middlewares para todas as rotas do grupo
     * @return Router
     */
    public function group($prefix, $callback, $middlewares = []) {
        // Guardar estado atual do router
        $currentRoutes = $this->routes;
        $this->routes = [];
        
        // Executar callback para adicionar rotas ao grupo
        call_user_func($callback, $this);
        
        // Aplicar prefixo e middlewares às rotas do grupo
        $groupRoutes = $this->routes;
        foreach ($groupRoutes as &$route) {
            $route['pattern'] = trim($prefix, '/') . '/' . $route['pattern'];
            $route['middlewares'] = array_merge($middlewares, $route['middlewares']);
        }
        
        // Restaurar as rotas originais mais as novas do grupo
        $this->routes = array_merge($currentRoutes, $groupRoutes);
        
        return $this;
    }
    
    /**
     * Define um middleware global para todas as rotas
     * 
     * @param string|callable $middleware Nome da classe de middleware ou função
     * @return Router
     */
    public function middleware($middleware) {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }
    
    /**
     * Define um callback para quando nenhuma rota for encontrada
     * 
     * @param callable $callback Função a ser chamada
     * @return Router
     */
    public function notFound($callback) {
        $this->notFoundCallback = $callback;
        return $this;
    }
    
    /**
     * Define a rota padrão quando nenhuma URL é fornecida
     * 
     * @param string $controller Nome do controlador
     * @param string $method Nome do método
     * @return Router
     */
    public function setDefaultRoute($controller, $method = 'index') {
        $this->defaultRoute = [
            'controller' => $controller,
            'method' => $method
        ];
        return $this;
    }
    
    /**
     * Verifica e trata requisições com métodos PUT, DELETE e PATCH via POST
     * 
     * @return string Método HTTP real da requisição
     */
    private function getRequestMethod() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        // Verificar se é POST com _method especificado (para PUT, DELETE, PATCH)
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }
        
        return $method;
    }
    
    /**
     * Obtém a URI da requisição atual
     * 
     * @return string URI requisitada
     */
    private function getRequestUri() {
        // Obter a URI completa
        $uri = $_SERVER['REQUEST_URI'];
        
        // Remover a query string se existir
        if (strpos($uri, '?') !== false) {
            $uri = strstr($uri, '?', true);
        }
        
        // Remover a base da URL
        $baseUri = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
        if ($baseUri && strpos($uri, $baseUri) === 0) {
            $uri = substr($uri, strlen($baseUri));
        }
        
        // Limpar barras extras
        return trim($uri, '/');
    }
    
    /**
     * Executa os middlewares registrados
     * 
     * @param array $middlewares Lista de middlewares a executar
     * @return bool Verdadeiro se todos os middlewares permitirem continuar
     */
    private function runMiddlewares($middlewares) {
        // Executar middlewares globais
        foreach ($this->globalMiddlewares as $middleware) {
            if (!$this->executeMiddleware($middleware)) {
                return false;
            }
        }
        
        // Executar middlewares específicos da rota
        foreach ($middlewares as $middleware) {
            if (!$this->executeMiddleware($middleware)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Executa um middleware específico
     * 
     * @param string|callable $middleware Nome da classe ou função
     * @return bool Resultado da execução do middleware
     */
    private function executeMiddleware($middleware) {
        if (is_callable($middleware)) {
            // Se for uma função, executar diretamente
            return call_user_func($middleware, $this->params);
        } else if (is_string($middleware)) {
            // Se for string, considerar nome da classe
            $middlewareClass = "App\\Middleware\\{$middleware}";
            if (class_exists($middlewareClass)) {
                $instance = new $middlewareClass();
                if (method_exists($instance, 'handle')) {
                    return $instance->handle($this->params);
                }
            }
        }
        
        // Se chegou aqui, considerar que o middleware não bloqueou
        return true;
    }
    
    /**
     * Despacha a requisição para o controlador apropriado
     * 
     * @return void
     */
    public function dispatch() {
        // Obter método HTTP e URI
        $requestMethod = $this->getRequestMethod();
        $requestUri = $this->getRequestUri();
        
        // Procurar por uma rota correspondente
        foreach ($this->routes as $route) {
            // Verificar se o método corresponde
            if ($route['method'] !== $requestMethod && $route['method'] !== 'ANY') {
                continue;
            }
            
            // Testar a correspondência do padrão da URL
            $pattern = '#^' . $route['pattern'] . '$#';
            if (preg_match($pattern, $requestUri, $matches)) {
                // Remover o match completo
                array_shift($matches);
                
                // Armazenar parâmetros identificados
                $this->params = $matches;
                
                // Executar middlewares
                if (!$this->runMiddlewares($route['middlewares'])) {
                    // Middleware bloqueou - não continuar execução
                    return;
                }
                
                // Executar o callback da rota
                if (is_callable($route['callback'])) {
                    // Se for uma função, executar diretamente
                    call_user_func_array($route['callback'], $matches);
                } else if (is_string($route['callback'])) {
                    // Se for string, buscar formato Controller@método
                    list($controller, $method) = explode('@', $route['callback']);
                    
                    // Namespace completo do controlador
                    $controllerClass = "App\\Controllers\\{$controller}";
                    
                    // Verificar se a classe existe
                    if (!class_exists($controllerClass)) {
                        throw new Exception("Controlador não encontrado: {$controllerClass}");
                    }
                    
                    // Instanciar controlador
                    $controllerInstance = new $controllerClass();
                    
                    // Verificar se o método existe
                    if (!method_exists($controllerInstance, $method)) {
                        throw new Exception("Método não encontrado: {$method} em {$controller}");
                    }
                    
                    // Executar método do controlador
                    call_user_func_array([$controllerInstance, $method], $matches);
                } else {
                    throw new Exception("Callback inválido para rota: {$route['pattern']}");
                }
                
                // Rota encontrada e processada, encerrar
                return;
            }
        }
        
        // Se chegou aqui, nenhuma rota correspondeu
        $this->handleNotFound($requestUri);
    }
    
    /**
     * Trata requisições para rotas não encontradas
     * 
     * @param string $requestUri URI requisitada
     * @return void
     */
    private function handleNotFound($requestUri) {
        // Se tiver um callback para notFound, executar
        if ($this->notFoundCallback && is_callable($this->notFoundCallback)) {
            call_user_func($this->notFoundCallback, $requestUri);
            return;
        }
        
        // Caso contrário, exibir erro 404
        header("HTTP/1.0 404 Not Found");
        
        if (file_exists(APP_PATH . '/Views/errors/404.php')) {
            // Renderizar view de erro
            view('errors/404');
        } else {
            // Exibir mensagem padrão
            echo '<h1>404 - Página não encontrada</h1>';
            echo '<p>A página que você está procurando não existe.</p>';
            echo '<p><a href="' . BASE_URL . '">Voltar para a página inicial</a></p>';
        }
    }
}

/**
 * -------------------------------------------------------------------------
 * INSTÂNCIA DO ROUTER E DEFINIÇÃO DAS ROTAS
 * -------------------------------------------------------------------------
 */
$router = new Router();

// Definir callback para página não encontrada
$router->notFound(function($uri) {
    view('errors/404', ['uri' => $uri]);
});

/**
 * -------------------------------------------------------------------------
 * ROTAS PÚBLICAS
 * -------------------------------------------------------------------------
 */

// Página inicial e informativas
$router->get('', 'HomeController@index');
$router->get('sobre', 'HomeController@about');
$router->get('contato', 'HomeController@contact');
$router->post('contato', 'HomeController@sendContact');
$router->get('termos', 'HomeController@terms');
$router->get('politica-privacidade', 'HomeController@privacy');
$router->get('como-comprar', 'HomeController@howToBuy');
$router->get('perguntas-frequentes', 'HomeController@faq');

// Produtos
$router->get('produtos', 'ProductController@index');
$router->get('produtos/categoria/([a-zA-Z0-9-]+)', 'ProductController@category');
$router->get('produtos/([a-zA-Z0-9-]+)', 'ProductController@show');
$router->get('produtos/busca', 'ProductController@search');

// Autenticação
$router->get('login', 'AuthController@login');
$router->post('login', 'AuthController@login');
$router->get('cadastro', 'AuthController@register');
$router->post('cadastro', 'AuthController@register');
$router->get('logout', 'AuthController@logout');
$router->get('recuperar-senha', 'AuthController@forgotPassword');
$router->post('recuperar-senha', 'AuthController@forgotPassword');
$router->get('redefinir-senha/([a-zA-Z0-9]+)', 'AuthController@resetPassword');
$router->post('redefinir-senha/([a-zA-Z0-9]+)', 'AuthController@resetPassword');

// Carrinho e checkout (não autenticado)
$router->get('carrinho', 'CartController@index');
$router->post('carrinho/adicionar', 'CartController@add');
$router->get('carrinho/remover/([0-9]+)', 'CartController@remove');
$router->post('carrinho/atualizar', 'CartController@update');
$router->get('carrinho/limpar', 'CartController@clear');
$router->get('checkout', 'CheckoutController@index', ['AuthMiddleware']);
$router->post('checkout/endereco', 'CheckoutController@address', ['AuthMiddleware']);
$router->get('checkout/frete', 'CheckoutController@shipping', ['AuthMiddleware']);
$router->post('checkout/frete', 'CheckoutController@setShipping', ['AuthMiddleware']);
$router->get('checkout/pagamento', 'CheckoutController@payment', ['AuthMiddleware']);
$router->post('checkout/finalizar', 'CheckoutController@finish', ['AuthMiddleware']);
$router->get('checkout/confirmacao/([0-9]+)', 'CheckoutController@confirmation', ['AuthMiddleware']);

// Links de referência
$router->get('ref/([a-zA-Z0-9]+)', 'ReferralController@process');
$router->get('ref/([a-zA-Z0-9]+)/produto/([0-9]+)', 'ReferralController@product');

/**
 * -------------------------------------------------------------------------
 * ROTAS DE ADMINISTRADOR
 * -------------------------------------------------------------------------
 */
$router->group('admin', function($router) {
    // Dashboard
    $router->get('', 'Admin\DashboardController@index');
    $router->get('dashboard', 'Admin\DashboardController@index');
    
    // Produtos
    $router->get('produtos', 'Admin\ProductController@index');
    $router->get('produtos/novo', 'Admin\ProductController@create');
    $router->post('produtos/novo', 'Admin\ProductController@store');
    $router->get('produtos/editar/([0-9]+)', 'Admin\ProductController@edit');
    $router->post('produtos/editar/([0-9]+)', 'Admin\ProductController@update');
    $router->get('produtos/excluir/([0-9]+)', 'Admin\ProductController@delete');
    $router->post('produtos/imagem/upload', 'Admin\ProductController@uploadImage');
    
    // Categorias
    $router->get('categorias', 'Admin\CategoryController@index');
    $router->get('categorias/nova', 'Admin\CategoryController@create');
    $router->post('categorias/nova', 'Admin\CategoryController@store');
    $router->get('categorias/editar/([0-9]+)', 'Admin\CategoryController@edit');
    $router->post('categorias/editar/([0-9]+)', 'Admin\CategoryController@update');
    $router->get('categorias/excluir/([0-9]+)', 'Admin\CategoryController@delete');
    
    // Estoque
    $router->get('estoque', 'Admin\StockController@index');
    $router->get('estoque/produto/([0-9]+)', 'Admin\StockController@product');
    $router->post('estoque/atualizar/([0-9]+)', 'Admin\StockController@update');
    $router->get('estoque/historico', 'Admin\StockController@history');
    $router->get('estoque/lotes', 'Admin\StockController@batches');
    $router->post('estoque/lotes/novo', 'Admin\StockController@addBatch');
    
    // Vendedores
    $router->get('vendedores', 'Admin\VendorController@index');
    $router->get('vendedores/novo', 'Admin\VendorController@create');
    $router->post('vendedores/novo', 'Admin\VendorController@store');
    $router->get('vendedores/editar/([0-9]+)', 'Admin\VendorController@edit');
    $router->post('vendedores/editar/([0-9]+)', 'Admin\VendorController@update');
    $router->get('vendedores/detalhes/([0-9]+)', 'Admin\VendorController@details');
    $router->get('vendedores/desativar/([0-9]+)', 'Admin\VendorController@disable');
    $router->get('vendedores/ativar/([0-9]+)', 'Admin\VendorController@enable');
    
    // Médicos
    $router->get('medicos', 'Admin\DoctorController@index');
    $router->get('medicos/novo', 'Admin\DoctorController@create');
    $router->post('medicos/novo', 'Admin\DoctorController@store');
    $router->get('medicos/editar/([0-9]+)', 'Admin\DoctorController@edit');
    $router->post('medicos/editar/([0-9]+)', 'Admin\DoctorController@update');
    $router->get('medicos/desativar/([0-9]+)', 'Admin\DoctorController@disable');
    $router->get('medicos/ativar/([0-9]+)', 'Admin\DoctorController@enable');
    $router->get('medicos/vendedores/([0-9]+)', 'Admin\DoctorController@vendors');
    $router->post('medicos/vendedores/adicionar', 'Admin\DoctorController@addVendor');
    $router->get('medicos/vendedores/remover/([0-9]+)/([0-9]+)', 'Admin\DoctorController@removeVendor');
    
    // Clientes
    $router->get('clientes', 'Admin\CustomerController@index');
    $router->get('clientes/detalhes/([0-9]+)', 'Admin\CustomerController@details');
    $router->get('clientes/desativar/([0-9]+)', 'Admin\CustomerController@disable');
    $router->get('clientes/ativar/([0-9]+)', 'Admin\CustomerController@enable');
    
    // Documentos
    $router->get('documentos/anvisa', 'Admin\DocumentController@anvisaApprovals');
    $router->get('documentos/visualizar/([0-9]+)', 'Admin\DocumentController@view');
    $router->post('documentos/aprovar/([0-9]+)', 'Admin\DocumentController@approve');
    $router->post('documentos/rejeitar/([0-9]+)', 'Admin\DocumentController@reject');
    $router->get('documentos/receitas', 'Admin\DocumentController@prescriptions');
    $router->get('documentos/receitas/visualizar/([0-9]+)', 'Admin\DocumentController@viewPrescription');
    $router->post('documentos/receitas/aprovar/([0-9]+)', 'Admin\DocumentController@approvePrescription');
    $router->post('documentos/receitas/rejeitar/([0-9]+)', 'Admin\DocumentController@rejectPrescription');
    
    // Pedidos
    $router->get('pedidos', 'Admin\OrderController@index');
    $router->get('pedidos/detalhes/([0-9]+)', 'Admin\OrderController@details');
    $router->post('pedidos/status/([0-9]+)', 'Admin\OrderController@updateStatus');
    $router->get('pedidos/nota-fiscal/([0-9]+)', 'Admin\OrderController@invoice');
    $router->post('pedidos/nota-fiscal/emitir/([0-9]+)', 'Admin\OrderController@generateInvoice');
    
    // Pagamentos
    $router->get('pagamentos', 'Admin\PaymentController@index');
    $router->get('pagamentos/pendentes', 'Admin\PaymentController@pending');
    $router->post('pagamentos/aprovar/([0-9]+)', 'Admin\PaymentController@approve');
    $router->post('pagamentos/rejeitar/([0-9]+)', 'Admin\PaymentController@reject');
    $router->get('pagamentos/detalhes/([0-9]+)', 'Admin\PaymentController@details');
    
    // Comissões
    $router->get('comissoes', 'Admin\CommissionController@index');
    $router->get('comissoes/vendedor/([0-9]+)', 'Admin\CommissionController@vendor');
    $router->get('comissoes/medico/([0-9]+)', 'Admin\CommissionController@doctor');
    $router->post('comissoes/pagar', 'Admin\CommissionController@pay');
    
    // Relatórios
    $router->get('relatorios/vendas', 'Admin\ReportController@sales');
    $router->get('relatorios/comissoes', 'Admin\ReportController@commissions');
    $router->get('relatorios/estoque', 'Admin\ReportController@stock');
    $router->get('relatorios/clientes', 'Admin\ReportController@customers');
    $router->get('relatorios/exportar/([a-z]+)', 'Admin\ReportController@export');
    
    // Configurações
    $router->get('configuracoes', 'Admin\SettingsController@index');
    $router->post('configuracoes/geral', 'Admin\SettingsController@general');
    $router->get('configuracoes/pagamento', 'Admin\SettingsController@payment');
    $router->post('configuracoes/pagamento', 'Admin\SettingsController@savePayment');
    $router->get('configuracoes/frete', 'Admin\SettingsController@shipping');
    $router->post('configuracoes/frete', 'Admin\SettingsController@saveShipping');
    $router->get('configuracoes/nota-fiscal', 'Admin\SettingsController@invoice');
    $router->post('configuracoes/nota-fiscal', 'Admin\SettingsController@saveInvoice');
    $router->get('configuracoes/email', 'Admin\SettingsController@email');
    $router->post('configuracoes/email', 'Admin\SettingsController@saveEmail');
    $router->get('configuracoes/backup', 'Admin\SettingsController@backup');
    $router->post('configuracoes/backup/gerar', 'Admin\SettingsController@generateBackup');
    
    // Páginas e conteúdo
    $router->get('paginas', 'Admin\PageController@index');
    $router->get('paginas/editar/([0-9]+)', 'Admin\PageController@edit');
    $router->post('paginas/editar/([0-9]+)', 'Admin\PageController@update');
    $router->get('faq', 'Admin\FaqController@index');
    $router->get('faq/novo', 'Admin\FaqController@create');
    $router->post('faq/novo', 'Admin\FaqController@store');
    $router->get('faq/editar/([0-9]+)', 'Admin\FaqController@edit');
    $router->post('faq/editar/([0-9]+)', 'Admin\FaqController@update');
    $router->get('faq/excluir/([0-9]+)', 'Admin\FaqController@delete');
    
}, ['AdminMiddleware']);

/**
 * -------------------------------------------------------------------------
 * ROTAS DE VENDEDOR
 * -------------------------------------------------------------------------
 */
$router->group('vendedor', function($router) {
    // Dashboard
    $router->get('', 'Vendor\DashboardController@index');
    $router->get('dashboard', 'Vendor\DashboardController@index');
    
    // Links
    $router->get('links', 'Vendor\LinkController@index');
    $router->get('links/gerar', 'Vendor\LinkController@generate');
    $router->post('links/gerar', 'Vendor\LinkController@store');
    $router->get('links/estatisticas/([0-9]+)', 'Vendor\LinkController@stats');
    $router->get('links/excluir/([0-9]+)', 'Vendor\LinkController@delete');
    
    // Vendas
    $router->get('vendas', 'Vendor\SalesController@index');
    $router->get('vendas/detalhes/([0-9]+)', 'Vendor\SalesController@details');
    
    // Médicos
    $router->get('medicos', 'Vendor\DoctorController@index');
    $router->get('medicos/cadastrar', 'Vendor\DoctorController@create');
    $router->post('medicos/cadastrar', 'Vendor\DoctorController@store');
    $router->get('medicos/editar/([0-9]+)', 'Vendor\DoctorController@edit');
    $router->post('medicos/editar/([0-9]+)', 'Vendor\DoctorController@update');
    $router->get('medicos/comissoes/([0-9]+)', 'Vendor\DoctorController@commissions');
    $router->post('medicos/comissoes/configurar/([0-9]+)', 'Vendor\DoctorController@setCommission');
    
    // Comissões
    $router->get('comissoes', 'Vendor\CommissionController@index');
    $router->get('comissoes/detalhes/([0-9]+)', 'Vendor\CommissionController@details');
    $router->get('comissoes/relatorio', 'Vendor\CommissionController@report');
    
    // Perfil
    $router->get('perfil', 'Vendor\ProfileController@index');
    $router->post('perfil/atualizar', 'Vendor\ProfileController@update');
    $router->get('perfil/senha', 'Vendor\ProfileController@password');
    $router->post('perfil/senha', 'Vendor\ProfileController@updatePassword');
    $router->get('perfil/banco', 'Vendor\ProfileController@bankInfo');
    $router->post('perfil/banco', 'Vendor\ProfileController@updateBankInfo');
    
}, ['VendorMiddleware']);

/**
 * -------------------------------------------------------------------------
 * ROTAS DE CLIENTE
 * -------------------------------------------------------------------------
 */
$router->group('cliente', function($router) {
    // Conta e perfil
    $router->get('', 'Customer\AccountController@index');
    $router->get('conta', 'Customer\AccountController@index');
    $router->get('conta/editar', 'Customer\AccountController@edit');
    $router->post('conta/editar', 'Customer\AccountController@update');
    $router->get('conta/senha', 'Customer\AccountController@password');
// Continuação das rotas de cliente
$router->post('conta/senha', 'Customer\AccountController@updatePassword');
    
// Endereços
$router->get('enderecos', 'Customer\AccountController@addresses');
$router->get('enderecos/novo', 'Customer\AccountController@newAddress');
$router->post('enderecos/novo', 'Customer\AccountController@storeAddress');
$router->get('enderecos/editar/([0-9]+)', 'Customer\AccountController@editAddress');
$router->post('enderecos/editar/([0-9]+)', 'Customer\AccountController@updateAddress');
$router->get('enderecos/excluir/([0-9]+)', 'Customer\AccountController@deleteAddress');
$router->get('enderecos/padrao/([0-9]+)', 'Customer\AccountController@setDefaultAddress');

// Documentos da ANVISA
$router->get('documentos', 'Customer\DocumentController@index');
$router->get('documentos/enviar', 'Customer\DocumentController@upload');
$router->post('documentos/enviar', 'Customer\DocumentController@store');

// Pedidos
$router->get('pedidos', 'Customer\OrderController@index');
$router->get('pedidos/detalhes/([0-9]+)', 'Customer\OrderController@details');
$router->get('pedidos/cancelar/([0-9]+)', 'Customer\OrderController@cancel');
$router->get('pedidos/nota/([0-9]+)', 'Customer\OrderController@invoice');

// Receitas médicas
$router->get('receitas', 'Customer\PrescriptionController@index');
$router->get('receitas/enviar/([0-9]+)', 'Customer\PrescriptionController@upload');
$router->post('receitas/enviar/([0-9]+)', 'Customer\PrescriptionController@store');

// Pagamentos
$router->get('pagamentos/([0-9]+)', 'Customer\PaymentController@process');
$router->post('pagamentos/([0-9]+)', 'Customer\PaymentController@execute');
$router->get('pagamentos/confirmacao/([0-9]+)', 'Customer\PaymentController@confirmation');
$router->get('pagamentos/cancelado/([0-9]+)', 'Customer\PaymentController@cancelled');

}, ['CustomerMiddleware']);

/**
* -------------------------------------------------------------------------
* ROTAS DE API
* -------------------------------------------------------------------------
*/
$router->group('api', function($router) {
// API pública
$router->get('produtos', 'Api\ProductController@index');
$router->get('produtos/([0-9]+)', 'Api\ProductController@show');
$router->get('categorias', 'Api\CategoryController@index');

// Webhooks
$router->post('webhooks/mercadopago', 'Api\WebhookController@mercadoPago');
$router->post('webhooks/nfeio', 'Api\WebhookController@nfeio');

// Rotas de API protegidas
$router->group('v1', function($router) {
    // Autenticação da API
    $router->post('auth/login', 'Api\AuthController@login');
    $router->post('auth/refresh', 'Api\AuthController@refresh');
    
    // Rotas protegidas por token
    $router->group('', function($router) {
        $router->get('profile', 'Api\UserController@profile');
        $router->get('orders', 'Api\OrderController@index');
        $router->get('orders/([0-9]+)', 'Api\OrderController@show');
    }, ['ApiAuthMiddleware']);
});

// Utilidades
$router->get('cep/([0-9]{8})', 'Api\UtilsController@cep');
$router->get('frete', 'Api\UtilsController@shipping');
}, []);

/**
* -------------------------------------------------------------------------
* ROTAS UTILITÁRIAS
* -------------------------------------------------------------------------
*/

// Rota para arquivos de upload protegidos (verificação de autenticação)
$router->get('uploads/protegido/([a-zA-Z0-9\/_-]+)', 'UtilsController@protectedFile', ['AuthMiddleware']);

// Rota para informações de CEP
$router->get('cep/([0-9]{8})', 'UtilsController@cep');

// Rota para cálculo de frete
$router->post('calcular-frete', 'UtilsController@calculateShipping');

/**
* -------------------------------------------------------------------------
* FUNÇÃO PARA CARREGAR ROTAS DE ARQUIVOS EXTERNOS
* -------------------------------------------------------------------------
*/

/**
* Carrega rotas definidas em arquivos externos
* 
* @param string $file Caminho para o arquivo relativo à pasta routes
* @return void
*/
function loadRoutes($file) {
global $router;

$routesPath = APP_PATH . '/Routes/' . $file . '.php';

if (file_exists($routesPath)) {
    require_once $routesPath;
} else {
    // Registrar erro se o arquivo não existir
    logMessage("Arquivo de rotas não encontrado: {$file}", 'error');
}
}

// Carrega rotas adicionais se existirem
if (is_dir(APP_PATH . '/Routes')) {
// Você pode adicionar outros arquivos de rota conforme necessário
// loadRoutes('admin');
// loadRoutes('vendor');
// loadRoutes('customer');
// loadRoutes('api');
}

// Retorna o router para uso em outras partes do sistema
return $router;