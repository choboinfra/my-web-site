동아시아 전략 게임 업그레이드 0.4

포함 파일
- lunch.html: 새 지도 렌더링, 게임 모드/팀 선택 UI
- game_api.php: 자유 대전/팀전 서버 처리, 아군 공격 방지, 팀 승리 판정

적용 전 주의
1. 현재 서버에서 두 파일을 반드시 백업하세요.
2. 게임 데이터는 /var/lib/site-live/game_state.json 에 있으므로 이번 배포로 초기화하지 마세요.
3. game_api.php와 lunch.html은 함께 배포하세요. 프런트엔드만 먼저 올리면 팀전 UI가 서버에서 작동하지 않습니다.
4. 이 지도는 지리적 분위기를 강화한 전략용 개략도이며, 정밀 GIS/실제 국경 데이터는 아닙니다.

권장 배포 (GitHub Actions가 main 변경을 두 VM으로 배포하는 경우)
1. 이 두 파일을 로컬에 다운로드합니다.
2. GitHub 저장소 https://github.com/choboinfra/my-web-site 의 루트에서 lunch.html과 game_api.php를 각각 교체하고 커밋합니다.
3. Actions 배포가 성공한 뒤 VM1과 VM2에서 아래를 확인합니다.

VM별 확인 명령
cd /var/www/html || exit 1
php -l game_api.php
sha256sum lunch.html game_api.php
grep -nE 'gameMode|teamChoice|EAST ASIA BUILD 0.4' lunch.html
grep -nE 'function game_decided|team_id|팀전에서는 아군 지역' game_api.php

두 VM의 SHA256 값이 같아야 합니다.

테스트 순서
1. 브라우저에서 Ctrl+F5로 강력 새로고침합니다.
2. 자유 대전: 두 계정으로 서로 다른 국가를 선택하고 방 생성/참가, 준비, 시작, 이동/공격을 확인합니다.
3. 팀전: 방 생성 시 팀전을 고르고 청색팀으로 생성합니다. 두 번째 계정은 방 코드로 참가하면서 팀을 적색팀으로 선택합니다. 양쪽 준비 후 시작합니다.
4. 팀전에서는 아군 지역 공격이 거부되고, 아군 지역으로 이동한 병력은 지원 병력으로 합류해야 합니다.
5. 새 전투방을 만들어 테스트하세요. 이전 방 데이터 구조와 기록은 유지됩니다.
