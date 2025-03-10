<?php
/**
 * DashboardController.php - Controlador da dashboard administrativa
 * 
 * Este controlador gerencia a exibição de métricas, gráficos e informações
 * importantes para o administrador na página inicial do painel administrativo.
 */

namespace App\Controllers\Admin;

use App\Models\OrderModel;
use App\Models\CustomerModel;
use App\Models\ProductModel;
use App\Models\DocumentModel;
use App\Models\PaymentModel;
use App\Models\StockModel;

class DashboardController {
    /**
     * @var OrderModel Modelo de pedidos
     */
    private $orderModel;
    
    /**
     * @var CustomerModel Modelo de clientes
     */
    private $customerModel;
    
    /**
     * @var ProductModel Modelo de produtos
     */
    private $productModel;
    
    /**
     * @var DocumentModel Modelo de documentos
     */
    private $documentModel;
    
    /**
     * @var PaymentModel Modelo de pagamentos
     */
    private $paymentModel;
    
    /**
     * @var StockModel Modelo de estoque
     */
    private $stockModel;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos
        $this->orderModel = new OrderModel();
        $this->customerModel = new CustomerModel();
        $this->productModel = new ProductModel();
        $this->documentModel = new DocumentModel();
        $this->paymentModel = new PaymentModel();
        $this->stockModel = new StockModel();
        
        // Verificar se o usuário é admin
        if (!is_admin()) {
            redirect('login');
        }
    }
    
    /**
     * Página inicial do dashboard administrativo
     */
    public function index() {
        // Obter período para filtros (padrão: últimos 30 dias)
        $period = $_GET['period'] ?? '30days';
        
        // Definir datas início e fim com base no período
        $endDate = date('Y-m-d');
        $startDate = $this->getStartDateByPeriod($period);
        
        // Obter métricas principais
        $metrics = $this->getMainMetrics($startDate, $endDate);
        
        // Obter gráficos
        $charts = $this->getChartData($startDate, $endDate);
        
        // Obter listas de itens pendentes
        $pendingItems = $this->getPendingItems();
        
        // Obter alertas de estoque baixo
        $stockAlerts = $this->getStockAlerts();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Dashboard Administrativo',
            'metrics' => $metrics,
            'charts' => $charts,
            'pendingItems' => $pendingItems,
            'stockAlerts' => $stockAlerts,
            'period' => $period
        ];
        
        // Carregar a view do dashboard
        view_with_layout('admin/dashboard', $data, 'admin');
    }
    
    /**
     * Calcula a data de início com base no período selecionado
     *
     * @param string $period Período selecionado (today, 7days, 30days, year, etc)
     * @return string Data de início no formato Y-m-d
     */
    private function getStartDateByPeriod($period) {
        $today = date('Y-m-d');
        
        switch ($period) {
            case 'today':
                return $today;
            
            case '7days':
                return date('Y-m-d', strtotime('-7 days'));
            
            case '30days':
                return date('Y-m-d', strtotime('-30 days'));
            
            case 'month':
                return date('Y-m-01'); // Primeiro dia do mês atual
            
            case 'lastmonth':
                return date('Y-m-d', strtotime('first day of last month'));
            
            case 'year':
                return date('Y-01-01'); // Primeiro dia do ano atual
            
            default:
                return date('Y-m-d', strtotime('-30 days'));
        }
    }
    
    /**
     * Obtém as métricas principais para o dashboard
     *
     * @param string $startDate Data de início
     * @param string $endDate Data de fim
     * @return array Métricas principais
     */
    private function getMainMetrics($startDate, $endDate) {
        // Total de vendas no período
        $totalSales = $this->orderModel->getTotalSales($startDate, $endDate);
        
        // Número de pedidos no período
        $orderCount = $this->orderModel->getOrderCount($startDate, $endDate);
        
        // Valor médio dos pedidos
        $averageOrderValue = $orderCount > 0 ? $totalSales / $orderCount : 0;
        
        // Novos clientes no período
        $newCustomers = $this->customerModel->getNewCustomersCount($startDate, $endDate);
        
        // Pedidos pendentes
        $pendingOrders = $this->orderModel->getOrderCountByStatus('pending');
        
        // Pedidos aguardando verificação de receita
        $awaitingPrescription = $this->orderModel->getOrderCountByStatus('awaiting_prescription');
        
        // Documentos ANVISA pendentes
        $pendingAnvisa = $this->documentModel->getPendingAnvisaCount();
        
        // Pagamentos pendentes
        $pendingPayments = $this->paymentModel->getPendingPaymentsCount();
        
        // Retornar métricas
        return [
            'totalSales' => $totalSales,
            'orderCount' => $orderCount,
            'averageOrderValue' => $averageOrderValue,
            'newCustomers' => $newCustomers,
            'pendingOrders' => $pendingOrders,
            'awaitingPrescription' => $awaitingPrescription,
            'pendingAnvisa' => $pendingAnvisa,
            'pendingPayments' => $pendingPayments
        ];
    }
    
    /**
     * Obtém dados para os gráficos do dashboard
     *
     * @param string $startDate Data de início
     * @param string $endDate Data de fim
     * @return array Dados para gráficos
     */
    private function getChartData($startDate, $endDate) {
        // Gráfico de vendas diárias/mensais
        $salesChart = $this->orderModel->getSalesByPeriod($startDate, $endDate);
        
        // Gráfico de produtos mais vendidos
        $topProducts = $this->orderModel->getTopProducts($startDate, $endDate, 5);
        
        // Gráfico de métodos de pagamento
        $paymentMethods = $this->paymentModel->getPaymentMethodsDistribution($startDate, $endDate);
        
        // Gráfico de status dos pedidos
        $orderStatus = $this->orderModel->getOrderStatusDistribution($startDate, $endDate);
        
        // Retornar dados dos gráficos
        return [
            'salesChart' => $salesChart,
            'topProducts' => $topProducts,
            'paymentMethods' => $paymentMethods,
            'orderStatus' => $orderStatus
        ];
    }
    
    /**
     * Obtém itens pendentes para ação do administrador
     *
     * @return array Itens pendentes
     */
    private function getPendingItems() {
        // Últimos pedidos aguardando aprovação
        $pendingOrders = $this->orderModel->getRecentOrdersByStatus('pending', 5);
        
        // Receitas médicas aguardando verificação
        $pendingPrescriptions = $this->documentModel->getRecentPrescriptions(5);
        
        // Documentos ANVISA aguardando aprovação
        $pendingAnvisa = $this->documentModel->getRecentAnvisaDocuments(5);
        
        // Pagamentos aguardando aprovação
        $pendingPayments = $this->paymentModel->getRecentPendingPayments(5);
        
        // Retornar itens pendentes
        return [
            'pendingOrders' => $pendingOrders,
            'pendingPrescriptions' => $pendingPrescriptions,
            'pendingAnvisa' => $pendingAnvisa,
            'pendingPayments' => $pendingPayments
        ];
    }
    
    /**
     * Obtém alertas de estoque baixo
     *
     * @return array Produtos com estoque baixo
     */
    private function getStockAlerts() {
        // Produtos com estoque baixo
        $lowStockProducts = $this->stockModel->getLowStockProducts(10);
        
        // Produtos com lotes próximos da expiração
        $expiringBatches = $this->stockModel->getExpiringBatches(30, 10);
        
        // Retornar alertas
        return [
            'lowStockProducts' => $lowStockProducts,
            'expiringBatches' => $expiringBatches
        ];
    }
    
    /**
     * AJAX: Endpoint para atualização de dados em tempo real
     */
    public function ajaxUpdate() {
        // Verificar se é uma requisição AJAX
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
            $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
            redirect('admin/dashboard');
            return;
        }
        
        // Obter período para filtros
        $period = $_POST['period'] ?? '30days';
        $endDate = date('Y-m-d');
        $startDate = $this->getStartDateByPeriod($period);
        
        // Obter dados solicitados
        $type = $_POST['type'] ?? 'metrics';
        
        switch ($type) {
            case 'metrics':
                $data = $this->getMainMetrics($startDate, $endDate);
                break;
                
            case 'charts':
                $data = $this->getChartData($startDate, $endDate);
                break;
                
            case 'pending':
                $data = $this->getPendingItems();
                break;
                
            case 'stock':
                $data = $this->getStockAlerts();
                break;
                
            default:
                $data = ['error' => 'Tipo de dados inválido'];
        }
        
        // Retornar dados em formato JSON
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}