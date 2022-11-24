###Variables
General rule for variable is it SHOULD be in camelCase

Bad:

```php
$articles_with_author
```

Good:

```php
$articlesWithAuthor
```

###Ternary operators

Bad:

```php
$result = $object instanceof Model ?
    $object->name :
   'A default value';
```

Good:

```php
$result = $object instanceof Model
    ? $object->name
    : 'A default value';

$name = $isFoo ? 'foo' : 'bar';
```

###Single responsibility principle

A class and a method should have only one responsibility.

Bad:

```php
public function getFullNameAttribute()
{
    if (auth()->user() && auth()->user()->hasRole('client') && auth()->user()->isVerified()) {
        return 'Mr. ' . $this->first_name . ' ' . $this->middle_name . ' ' . $this->last_name;
    } else {
        return $this->first_name[0] . '. ' . $this->last_name;
    }
}
```

Good:

```php
public function getFullNameAttribute()
{
    return $this->isVerifiedClient() ? $this->getFullNameLong() : $this->getFullNameShort();
}

public function isVerifiedClient()
{
    return auth()->user() && auth()->user()->hasRole('client') && auth()->user()->isVerified();
}

public function getFullNameLong()
{
    return "Mr. {$this->first_name} {$this->middle_name} {$this->last_name}";
}

public function getFullNameShort()
{
    return "{$this->first_name[0]}. {$this->last_name}";
}
```

###Mass assignment

Bad:
```php
$article = new Article;
$article->title = $request->title;
$article->content = $request->content;
$article->verified = $request->verified;
// Add category to article
$article->category_id = $category->id;
$article->save();
```

Good:
```php
$category->articles()->create($request->only(...));
```
### **Validation**

Move validation from controllers to Request classes.

Bad:

```php
public function store(Request $request)
{
    $request->validate([
        'title' => 'required|unique:posts|max:255',
        'body' => 'required',
        'publish_at' => 'nullable|date',
    ]);

    ....
}
```

Good:

```php
public function store(PostRequest $request)
{
    ....
}

class PostRequest extends Request
{
    public function rules()
    {
        return [
            'title' => 'required|unique:posts|max:255',
            'body' => 'required',
            'publish_at' => 'nullable|date',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(..., 200));
    }

}
```

### **Do not use eager loading (N + 1 problem)**

Bad (for 100 users, 101 DB queries will be executed):

```php
foreach (User::all() as $user) {
    $user->profile->name;
}
```

Good (for 100 users, 2 DB queries will be executed):

```php
$users = User::with('profile')->get();

...

foreach (User::all() as $user) {
    $user->profile->name;
}
```
### **Use IoC container or facades instead of new Class**

new Class syntax creates tight coupling between classes and complicates testing. Use IoC container or facades instead.

Bad:

```php
public function show($id)
{
    $user = User::findOrFail($id);
}
```

Good:

```php
public function show(User $user)
{
    ....
}
```

### **Do not get data from the `.env` file directly**

Pass the data to config files instead and then use the `config()` helper function to use the data in an application.

Bad:

```php
$apiKey = env('API_KEY');
```

Good:

```php
// config/api.php
'key' => env('API_KEY'),

// Use the data
$apiKey = config('api.key');
```

### Use Resource Controllers unless you have any particular reason not to do so

Bad:

```php
Route::get('domains_list', 'DomainController@list');
Route::post('create_or_save', 'DomainController@create_or_save');
Route::get('show_edit', 'DomainController@show_edit');
Route::post('delete', 'DomainController@delete');

class DomainController extends Controller
{
    public function list(){} // list domains
    public function create_or_save(){} // show create form then handle save
    public function show_edit($id){} // show a single domain then show edit page
    public function delete($id){} // delete a domain
}
```

Good

```php
Route::apiResource('domains', 'DomainController');

...

class DomainController extends Controller
{
    public function index() {} // list domains
    public function store(Request $request) {} // handle the form POST
    public function show(Domain $domain) {} // show a single domain
    public function update(Request $request, Domain $domain) {} // handle show edit page POST
    public function destroy(Domain $domain) {} // delete a domain
}
```

### If statements

Always use curly brackets.
```php
// Bad
if ($condition) ...

// Good
if ($condition) {
   ...
}
```

Generally a function should have its unhappy path first and its happy path last. In most cases this will cause the happy path being in an unindented part of the function which makes it more readable.

```php
// Bad

if ($goodCondition) {
  // do work
}

throw new Exception;
```

```php
// Good

if (!$goodCondition) {
  throw new Exception;
}

// do work
```
