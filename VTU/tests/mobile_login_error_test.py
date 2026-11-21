"""Source contracts only; native JUnit tests require an Android build toolchain."""
from pathlib import Path
root = Path(__file__).resolve().parents[1] / 'ANDROID - iOS'
helpers = []
checks = 0
for name, package in [('DG7-Android','com.datagifting.app'), ('JIKABIZ-Android','com.jikabiz.app'), ('MZEEVTU-Android','com.mzeevtu')]:
    project = root / name / 'app/src'
    rel = Path(*package.split('.')) / 'data/repository'
    repo = (project/'main/java'/rel/'AuthRepository.kt').read_text()
    helper = (project/'main/java'/rel/'LoginHttpError.kt').read_text()
    tests = (project/'test/java'/rel/'LoginHttpErrorTest.kt').read_text()
    assert repo.count('LoginHttpError.message(response.code(), response.errorBody()?.string())') == 2, name
    assert 'LoginOutcome.Failure("Server error:' not in repo, name
    assert '"message", "desc"' in helper and 'JsonParser.parseString' in helper, name
    assert 'it.asJsonPrimitive.isString' in helper and "!it.contains('<')" in helper, name
    assert 'BUSINESS_WEB_ONLY' in tests and '<html>Forbidden</html>' in tests, name
    assert repo.index('if (!response.isSuccessful)') < repo.index('persistLogin(data)'), name
    helpers.append(helper.replace(package, 'PACKAGE'))
    checks += 6
assert len(set(helpers)) == 1
print(f'{checks + 1} mobile login error source checks passed (not native execution).')
