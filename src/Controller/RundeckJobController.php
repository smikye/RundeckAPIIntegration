<?php
namespace App\Controller;

use GuzzleHttp\Subscriber\Redirect;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\Request;

class RundeckJobController extends AbstractController
{
    /** @var ParameterBagInterface */
    private $params;

    /** @var Request */
    private $request;

    /** @var  integer */
    private $total;

    public function __construct(ParameterBagInterface $params) {
        $this->params = $params;
        $this->request = Request::createFromGlobals();
    }

    /**
     * @Route("/", name="main")
     */
    public function index()
    {

        if (false === $this->isAuth()) {
            $this->auth();
            return $this->redirect($this->generateUrl('main'));
        } else {
            if (((int)$this->request->get('page') - 1) > 0)
                $results = $this->getExecutions(((int)$this->request->get('page') - 1) * (int)$this->params->get('get_executions_max'));
            else
                $results = $this->getExecutions();

            $pagesCount = ceil($this->total / $this->params->get('get_executions_max'));

            return $this->render('rundeck-jobs.html.twig', [
                'executions' => $results['executions'],
                'settings' => $results['settings'],
                'pageCount' => $pagesCount
            ]);
        }
    }

    /**
     * Check Rundeck authentication
     *
     * @return boolean
     */
    private function isAuth() {
        if(isset($_COOKIE['JSESSIONID']))
            return true;

        return false;
    }

    /**
     * Authenticate to Rundeck
     *
     * @return boolean
     */
    private function auth() {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->params->get('rundeck_auth_url'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded'
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            "j_username=" . $this->params->get('rundeck_username')
            . "&j_password=" . $this->params->get('rundeck_password')
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        if (curl_error($ch)) {
            $error_msg = curl_error($ch);
        }

        if (!isset($error_msg)) {
            preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
            $cookies = array();
            foreach($matches[1] as $item) {
                parse_str($item, $cookie);
                $cookies = array_merge($cookies, $cookie);
            }
            foreach ($cookies as $key => $value){
                setcookie($key, $value, time() + 600);
            }

            curl_close ($ch);

            return true;
        }

        throw new BadRequestHttpException($error_msg);
    }

    /**
     * Get executions from Rundeck
     *
     * @param $offset int
     *
     * @return boolean | array
     */
    private function getExecutions(int $offset = 0) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->params->get('rundeck_get_executions_url')
            . "?max=" . $this->params->get('get_executions_max')
            . "&offset=" . $offset
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Cookie: JSESSIONID=".$_COOKIE['JSESSIONID'],
            'Accept: application/json'
        ));

        $server_output = curl_exec($ch);

        if (curl_error($ch)) {
            $error_msg = curl_error($ch);
        }

        curl_close ($ch);

        if (!isset($error_msg)) {
            $result = json_decode($server_output, true);

            $executions = [];

            $this->total = (int)$result['paging']['total'];

            foreach ($result['executions'] as $execution) {
                $array = [];
                $catalogViewCode = '';
                $exportTypes = '';
                $dateStarted = date('d/m/Y H:i', $execution['date-started']['unixtime']);
                $dateEnded = date('d/m/Y H:i', $execution['date-ended']['unixtime']);

                switch ($execution['job']['options']['catalog_view_code']) {
                    case 'SEU':
                        $catalogViewCode = $execution['job']['options']['catalog_view_code'] . ' / Europe';
                        break;
                    case 'SUS':
                        $catalogViewCode = $execution['job']['options']['catalog_view_code'] . ' / United States';
                        break;
                    case 'SWW':
                        $catalogViewCode = $execution['job']['options']['catalog_view_code'] . ' / Worldwide';
                        break;
                }

                switch ($execution['job']['options']['export_types']) {
                    case 'catalog':
                        $exportTypes = $execution['job']['options']['export_types'] . ' / Catalog Tree';
                        break;
                    case 'assignements':
                        $exportTypes = $execution['job']['options']['export_types'] . ' / Product assignements';
                        break;
                    case 'products':
                        $exportTypes = $execution['job']['options']['export_types'] . ' / Products\' Datas';
                        break;
                }

                $array['id'] = $execution['id'];
                $array['status'] = $execution['status'];
                $array['date_started'] = $dateStarted;
                $array['date_ended'] = $dateEnded;
                $array['job']['catalog_view_code'] = $catalogViewCode;
                $array['job']['export_types'] = $exportTypes;
                $array['job']['permalink'] = $execution['job']['permalink'];

                array_push($executions, $array);
            }

            $settings['offset'] = (int)$offset;
            $settings['page_num'] = $offset / $this->params->get('get_executions_max') + 1;
            $settings['total'] = $this->total;

            return array('executions' => $executions, 'settings' => $settings);
        }

        throw new BadRequestHttpException($error_msg);
    }

    /**
     * @Route("/run", name="runJob")
     *
     * Run the job on Rundeck
     *
     * @return RedirectResponse
     */
    public function runJob() {

        if (false === $this->isAuth())
            $this->auth();

        $paramsStr = '-catalog_view_code ';
        $errors = false;

        if(!empty($this->request->get('catalog_view_code')))
            $paramsStr .= $this->request->get('catalog_view_code');
        else
            $errors = 'required';

        if(!empty($this->request->get('export_types')))
            $paramsStr .= ' -export_types ' . $this->request->get('export_types');
        else
            $errors = 'required';

        if(!empty($this->request->get('gnc_codes')))
            $paramsStr .= ' -gnc_codes ' . $this->request->get('gnc_codes');

        if(!empty($errors))
             return $this->redirect($this->generateUrl('main', array(
                'errors' => $errors
            )));

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->params->get('rundeck_run_job'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            "Cookie: JSESSIONID=".$_COOKIE['JSESSIONID'],
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            "argString=" . $paramsStr
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        if (curl_error($ch))
            return $this->redirect($this->generateUrl('main', array(
                'errors' => 'badRequest'
            )));

        curl_close ($ch);

        $result = json_decode($response, true);

        if((isset($result['status'])) && ($result['status'] === 'running'))
            return $this->redirect($this->generateUrl('main', array(
                'success' => 'true'
            )));

        return $this->redirect($this->generateUrl('main', array(
            'errors' => 'badRequest'
        )));
    }
}