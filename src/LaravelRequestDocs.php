<?php

namespace ExclusiveDev\LaravelRequestDocs;

use ErrorException;
use Route;
use ReflectionMethod;
use Illuminate\Http\Request;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Exception;
use Throwable;
use ReflectionException;

class LaravelRequestDocs
{

    public function getDocs()
    {
        $docs = [];
        $excludePatterns = config('request-docs.hide_matching') ?? [];
        $controllersInfo = $this->getControllersInfo();
        $controllersInfo = $this->appendRequestRules($controllersInfo);
        foreach ($controllersInfo as $controllerInfo) {
            try {
                $exclude = false;
                foreach ($excludePatterns as $regex) {
                    $uri = $controllerInfo['uri'];
                    if (preg_match($regex, $uri)) {
                        $exclude = true;
                    }
                }
                if (!$exclude) {
                    $docs[] = $controllerInfo;
                }
            } catch (Exception $exception) {
                continue;
            }
        }
        return array_filter($docs);
    }

    public function sortDocs(array $docs, $sortBy = 'default'): array
    {
        if ($sortBy === 'route_names') {
            sort($docs);
            return $docs;
        }
        $sorted = [];
        $methods = [
            'GET',
            'POST',
            'PUT',
            'PATCH',
            'DELETE',
        ];
        foreach ($methods as $method) {
            foreach ($docs as $key => $doc) {
                if (in_array($method, $doc['methods'])) {
                    $sorted[] = $doc;
                }
            }
        }
        return $sorted;
    }

    public function getControllersInfo(): array
    {
        $controllersInfo = [];
        $routes = collect(Route::getRoutes());
        $onlyRouteStartWith = config('request-docs.only_route_uri_start_with') ?? '';

        foreach ($routes as $route) {
            if ($onlyRouteStartWith && !Str::startsWith($route->uri, $onlyRouteStartWith)) {
                continue;
            }

            try {
                /// Show Only Controller Name
                // echo $route->action['controller'] . PHP_EOL;
                $controllerFullPath = explode('@', $route->action['controller'])[0];
                $getStartWord = strrpos(explode('@', $route->action['controller'])[0], '\\') + 1;
                $controllerName = substr($controllerFullPath, $getStartWord);

                /// Has Auth Token
                $hasAuthToken = !is_array($route->action['middleware']) ? [$route->action['middleware']] : $route->action['middleware'];

                $controllersInfo[] = [
                    'uri'                   => $route->uri,
                    'methods'               => $route->methods,
                    'middlewares'           => !is_array($route->action['middleware']) ? [$route->action['middleware']] : $route->action['middleware'],
                    'controller'            => $controllerName,
                    'controller_full_path'  => $controllerFullPath,
                    'method'                => explode('@', $route->action['controller'])[1],
                    'rules'                 => [],
                    'docBlock'              => "",
                    'bearer'                => in_array('auth:api', $hasAuthToken)
                ];
            } catch (Exception $e) {
                continue;
            }
        }

        return $controllersInfo;
    }

    public function appendRequestRules(array $controllersInfo)
    {
        foreach ($controllersInfo as $index => $controllerInfo) {
            $controller       = $controllerInfo['controller_full_path'];
            $method           = $controllerInfo['method'];
            // echo $controller . '@' . $method . PHP_EOL;
            try {
                $reflectionMethod = new ReflectionMethod($controller, $method);
                $params           = $reflectionMethod->getParameters();

                foreach ($params as $param) {
                    if (!$param->getType()) {
                        continue;
                    }
                    $requestClassName = $param->getType()->getName();
                    $requestClass = null;
                    try {
                        $requestClass = new $requestClassName();
                    } catch (Throwable $th) {
                        //throw $th;
                    }
                    if ($requestClass instanceof FormRequest) {
                        try {
                            $controllersInfo[$index]['rules'] = $this->flattenRules($requestClass->rules());
                        } catch (\Exception $th) {
                            $controllersInfo[$index]['rules'] = $this->rulesByRegex($requestClassName);
                        }
                        $controllersInfo[$index]['docBlock'] = $this->lrdDocComment($reflectionMethod->getDocComment());
                    }
                }
            } catch (ReflectionException $e) {
                // log/report
                continue;
            }
        }
        return $controllersInfo;
    }

    public function lrdDocComment($docComment): string
    {
        $lrdComment = "";
        $found = false;
        foreach (explode("\n", $docComment) as $comment) {
            $comment = trim($comment);
            // check contains in string
            if (Str::contains($comment, '@lrdend')) {
                break;
            } else if (Str::contains($comment, '@lrd')) {
                $found = true;
                continue;
            }  
            
            if ($found) {                
                $comment = trim(substr($comment, 1));                
                // remove first character from string
                $lrdComment .= $comment . "\n";
            }
        }
        return $lrdComment;
    }

    // get text between first and last tag
    private function getTextBetweenTags($docComment, $tag1, $tag2)
    {
        $docComment = trim($docComment);
        $start = strpos($docComment, $tag1);
        $end = strpos($docComment, $tag2);
        $text = substr($docComment, $start + strlen($tag1), $end - $start - strlen($tag1));
        return $text;
    }

    public function flattenRules($mixedRules)
    {
        $rules = [];
        foreach ($mixedRules as $attribute => $rule) {
            if (is_object($rule)) {
                $rule = get_class($rule);
                $rules[$attribute][] = $rule;
            } elseif (is_array($rule)) {
                $rulesStrs = [];
                foreach ($rule as $ruleItem) {
                    $rulesStrs[] = is_object($ruleItem) ? get_class($ruleItem) : $ruleItem;
                }
                $rules[$attribute][] = implode("|", $rulesStrs);
            } else {
                $rules[$attribute][] = $rule;
            }
        }

        return $rules;
    }

    public function rulesByRegex($requestClassName)
    {
        $data = new ReflectionMethod($requestClassName, 'rules');
        $lines = file($data->getFileName());
        $rules = [];
        for ($i = $data->getStartLine() - 1; $i <= $data->getEndLine() - 1; $i++) {
            preg_match_all("/(?:'|\").*?(?:'|\")/", $lines[$i], $matches);
            $rules[] =  $matches;
        }

        $rules = collect($rules)
            ->filter(function ($item) {
                return count($item[0]) > 0;
            })
            ->transform(function ($item) {
                $fieldName = str_replace(['"',"'"],'',$item[0][0]);
                $definedFieldRules = collect(array_slice($item[0], 1))->transform(function ($rule) {
                    return str_replace(['"',"'"],'',$rule);
                })->toArray();

                return ['key' => $fieldName, 'rules' => $definedFieldRules];
            })
            ->keyBy('key')
            ->transform(function ($item) {
                return $item['rules'];
            })->toArray();

        return $rules;
    }
}
