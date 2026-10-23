동아시아 전략 게임 v0.5 — 지도/UI 개편 + 요새화 시스템

포함 파일
- lunch.html: 프리미엄 다크 UI, 전략 지도 스타일 개선, 확대/축소, 상세 지역 정보, 요새화 버튼
- game_api.php: 요새화 서버 처리, 자원 비용/최대 3단계, 요새 방어 보너스

새 게임 기능
- 지역 요새화: 소유한 지역을 선택하고 '지역 요새화'를 누릅니다.
- 단계별 비용: 1단계 식량 20/금속 15, 2단계 식량 35/금속 35, 3단계 식량 50/금속 55
- 단계당 방어력 18% 보너스(최대 54%); 요새화는 서버에서 소유권/자원/최대 단계 검사
- 지도 확대/축소는 클라이언트 표시만 변경하며 게임 상태를 바꾸지 않습니다.

중요
- 기존 게임 데이터 /var/lib/site-live/game_state.json은 초기화하지 마세요.
- 두 파일을 함께 배포하세요. API와 화면 파일 버전이 달라지면 요새화 UI가 작동하지 않을 수 있습니다.
- 이번 지도는 직접 작성한 전략용 SVG 개략도이며 실제 GIS 경계 데이터는 아닙니다.
- 실제 운영 전에 새 방으로 자유 대전/팀전, 공격/이동, 요새화, 새로고침 동기화를 테스트하세요.

권장 적용 절차 (VM1에서)
cd /var/www/html || exit 1
sudo cp -p lunch.html /var/lib/site-live/lunch_before_v05_$(date +%Y%m%d_%H%M%S).html
sudo cp -p game_api.php /var/lib/site-live/game_api_before_v05_$(date +%Y%m%d_%H%M%S).php

그 다음 GitHub 저장소 루트의 lunch.html과 game_api.php를 패키지의 파일로 교체하고 커밋/푸시하세요. GitHub Actions가 두 VM으로 배포되도록 기다립니다.

VM1 및 VM2에서 각각 검사
cd /var/www/html || exit 1
php -l game_api.php
sha256sum lunch.html game_api.php
grep -n 'fortifyBtn' lunch.html
grep -n "\$action==='fortify'" game_api.php

두 VM에서 파일별 SHA256 값이 일치해야 합니다. 그 다음 브라우저에서 Ctrl+F5로 새로고침하고 새 테스트 방에서 확인하세요.

테스트 순서
1. 로그인 → 새 방 생성/참가 → 양쪽 준비 → 전쟁 시작
2. 내 점령 지역 선택 → 요새화 버튼 활성화 확인
3. 요새화 실행 후 식량/금속 차감과 지역 방어 단계 확인
4. 적이 요새 지역을 공격했을 때 일반 지역보다 방어력이 높게 적용되는지 확인
5. 확대/축소 버튼, 지역 선택, 병력 이동, 두 계정 화면 동기화 확인

롤백
GitHub에서 이전 lunch.html/game_api.php 버전으로 되돌린 뒤 기존 배포 파이프라인으로 양쪽 VM에 배포하세요. 게임 상태 JSON은 수정하지 않았으므로 데이터 초기화는 필요하지 않습니다.
